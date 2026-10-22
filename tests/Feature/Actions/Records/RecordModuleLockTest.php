<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\AddRecordField;
use App\Actions\Records\AddRecordFieldHeading;
use App\Actions\Records\AddRecordRule;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecord;
use App\Actions\Records\DeleteRecordField;
use App\Actions\Records\DeleteRecordFieldHeading;
use App\Actions\Records\DeleteRecordRule;
use App\Actions\Records\ReorderRecordFields;
use App\Actions\Records\UpdateRecordField;
use App\Actions\Records\UpdateRecordFieldHeading;
use App\Actions\Records\UpdateRecordModule;
use App\Actions\Records\UpdateRecordRule;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordRule;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールごとの書き込みの直列化(docs の 設計/業務データの入れ物/データモデル/値の形と直列化.md 4 節): レコードの書き込みは業務モジュールの
 * 勧告ロックを共有で、定義の書き込みは排他で、定義を読む・行を書く前に取る。鍵はトップの業務モジュールの id から作り、明細のモジュールは親の鍵。
 * 発行した SQL の順と、鍵の文が業務処理のトランザクションの中(レベル 2 以上。テストの TX がレベル 1)で出ることを確かめる。
 * 鍵が別の接続を待たせる効き目は、ここでは確かめない(確かめる形 = CommonMasterLockTest の第 2 の接続)。
 */
final class RecordModuleLockTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    /** 業務モジュールの鍵の起点(App\Actions\Records\LocksRecordModule と同じ値) */
    private const LOCK_BASE = 4_200_000_000_000_000;

    private const SHARED = 'pg_advisory_xact_lock_shared(';

    private const EXCLUSIVE = 'pg_advisory_xact_lock(';

    /** @var list<array{string, array<mixed>, int}> 記録中に発行された SQL と束縛の値とトランザクションのレベル */
    private array $log = [];

    private bool $recording = false;

    protected function setUp(): void
    {
        parent::setUp();
        DB::listen(function (QueryExecuted $query) {
            if ($this->recording) {
                $this->log[] = [$query->sql, $query->bindings, $query->connection->transactionLevel()];
            }
        });
    }

    public function test_record_writes_take_the_module_lock_shared_before_reading_the_definition(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'title');
        $record = Record::factory()->create(['record_module_id' => $module->id]);
        $removed = Record::factory()->create(['record_module_id' => $module->id]);
        $key = self::LOCK_BASE + $module->id;

        $created = $this->queries(fn () => app(CreateRecord::class)->execute($this->contextFor(), 'projects', new RecordInput(['title' => '新'])));
        $version = $record->refresh()->version();
        $updated = $this->queries(fn () => app(UpdateRecord::class)->execute($this->contextFor(), 'projects', $record->id, new RecordInput(['title' => '変']), $version));
        $deleted = $this->queries(fn () => app(DeleteRecord::class)->execute($this->contextFor(), 'projects', $removed->id));

        $this->assertLockedBefore(self::SHARED, $key, 'from "record_fields"', $created);
        $this->assertLockedBefore(self::SHARED, $key, 'for no key update', $updated);
        $this->assertLockedBefore(self::SHARED, $key, 'delete from "records"', $deleted);
    }

    public function test_definition_writes_take_the_module_lock_exclusively_and_a_details_module_uses_its_parent_key(): void
    {
        $module = $this->recordModule('projects');
        $this->field($module, 'start', RecordFieldType::Date);
        $this->field($module, 'end', RecordFieldType::Date);
        $tasks = $this->detailsField($module, 'tasks');
        $key = self::LOCK_BASE + $module->id;

        $added = $this->queries(fn () => app(AddRecordField::class)->execute('projects', ['code' => 'memo', 'name' => 'メモ', 'type' => 'string']));
        $changed = $this->queries(fn () => app(UpdateRecordField::class)->execute('projects', 'memo', ['name' => '備考']));
        $childAdded = $this->queries(fn () => app(AddRecordField::class)->execute($this->childOf($tasks)->code, ['code' => 'hours', 'name' => '時間', 'type' => 'integer']));
        $ruleAdded = $this->queries(fn () => app(AddRecordRule::class)->execute('projects', ['kind' => 'compare', 'target' => 'end', 'operator' => 'ge', 'compared' => 'start']));
        $rule = RecordRule::query()->where('record_module_id', $module->id)->sole();
        $ruleChanged = $this->queries(fn () => app(UpdateRecordRule::class)->execute('projects', $rule->id, ['kind' => 'compare', 'target' => 'end', 'operator' => 'gt', 'compared' => 'start']));
        $ruleDeleted = $this->queries(fn () => app(DeleteRecordRule::class)->execute('projects', $rule->id));
        $fieldDeleted = $this->queries(fn () => app(DeleteRecordField::class)->execute('projects', 'memo'));
        $fieldItems = array_map(fn (string $code) => ['kind' => 'field', 'code' => $code], ['tasks', 'end', 'start']);
        $reordered = $this->queries(fn () => app(ReorderRecordFields::class)->execute('projects', $fieldItems));
        $headingAdded = $this->queries(fn () => $this->addHeading('期間'));
        $headingId = RecordFieldHeading::query()->where('record_module_id', $module->id)->sole()->id;
        $headingChanged = $this->queries(fn () => $this->renameHeading($headingId, '期間と予算'));
        $headingDeleted = $this->queries(fn () => $this->deleteHeading($headingId));
        $moduleChanged = $this->queries(fn () => app(UpdateRecordModule::class)->execute('projects', ['description' => '説明']));
        $childModuleChanged = $this->queries(fn () => app(UpdateRecordModule::class)->execute($this->childOf($tasks)->code, ['name' => '作業']));

        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'from "record_fields"', $added);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'from "record_fields"', $changed);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'from "record_fields"', $childAdded);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'from "record_fields"', $ruleAdded);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'from "record_fields"', $ruleChanged);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'delete from "record_rules"', $ruleDeleted);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'from "record_fields"', $fieldDeleted);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'from "record_fields"', $reordered);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'from "record_fields"', $headingAdded);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'update "record_field_headings"', $headingChanged);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'delete from "record_field_headings"', $headingDeleted);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'update "record_modules"', $moduleChanged);
        $this->assertLockedBefore(self::EXCLUSIVE, $key, 'update "record_modules"', $childModuleChanged);
        $childKey = self::LOCK_BASE + $this->childOf($tasks)->id;
        $this->assertNull($this->indexOf($childAdded, fn (string $sql, array $bindings) => ($bindings[0] ?? null) === $childKey), '明細のモジュールが自分の id の鍵を取った');
        $this->assertNull($this->indexOf($childModuleChanged, fn (string $sql, array $bindings) => ($bindings[0] ?? null) === $childKey), '明細のモジュールの変更が自分の id の鍵を取った');
    }

    private function addHeading(string $name): void
    {
        app(AddRecordFieldHeading::class)->execute('projects', ['name' => $name]);
    }

    private function renameHeading(int $headingId, string $name): void
    {
        app(UpdateRecordFieldHeading::class)->execute('projects', $headingId, ['name' => $name]);
    }

    private function deleteHeading(int $headingId): void
    {
        app(DeleteRecordFieldHeading::class)->execute('projects', $headingId);
    }

    /**
     * 処理が発行した SQL と束縛の値とトランザクションのレベル(順に)。
     *
     * @return list<array{string, array<mixed>, int}>
     */
    private function queries(Closure $action): array
    {
        [$this->log, $this->recording] = [[], true];

        try {
            $action();
        } finally {
            $this->recording = false;
        }

        $this->assertLocksInsideTheOperationTransaction($this->log);

        return $this->log;
    }

    /**
     * 鍵の文(行の鍵と勧告ロック)は業務処理の DB::transaction の中で出す。外で取ると、本番では文の終わりに鍵が外れる(CaseLockOrderTest と同じ形)。
     *
     * @param  list<array{string, array<mixed>, int}>  $queries
     */
    private function assertLocksInsideTheOperationTransaction(array $queries): void
    {
        foreach ($queries as [$sql, , $level]) {
            $sql = strtolower($sql);

            if (preg_match('/ for (no key )?(update|share)$/D', $sql) === 1 || str_contains($sql, 'pg_advisory')) {
                $this->assertGreaterThanOrEqual(2, $level, "鍵の文 {$sql} がテストの TX(レベル 1)で出た = 業務処理の DB::transaction の外で鍵を取っている");
            }
        }
    }

    /**
     * 鍵 key のロックの SQL(lock)が、SQL の断片 before を含む最初の文より前にある。
     *
     * @param  list<array{string, array<mixed>, int}>  $queries
     */
    private function assertLockedBefore(string $lock, int $key, string $before, array $queries): void
    {
        $lockedAt = $this->indexOf($queries, fn (string $sql, array $bindings) => str_contains($sql, $lock) && ($bindings[0] ?? null) === $key);
        $readAt = $this->indexOf($queries, fn (string $sql) => str_contains(strtolower($sql), $before));

        $this->assertNotNull($lockedAt, "{$lock}{$key}) が発行されていない");
        $this->assertNotNull($readAt, "{$before} が発行されていない");
        $this->assertLessThan($readAt, $lockedAt, "{$lock}{$key}) が {$before} より後");
    }

    /**
     * @param  list<array{string, array<mixed>, int}>  $queries
     * @param  Closure(string, array<mixed>): bool  $matches
     */
    private function indexOf(array $queries, Closure $matches): ?int
    {
        foreach ($queries as $index => [$sql, $bindings]) {
            if ($matches($sql, $bindings)) {
                return $index;
            }
        }

        return null;
    }
}
