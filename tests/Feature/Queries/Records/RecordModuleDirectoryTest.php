<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Records\Api\RecordModuleDirectory;
use App\Queries\Records\Api\RecordModuleVisibilityStops;
use App\Queries\Records\RecordAdminQuery;
use App\Queries\Records\RecordModuleVisibilityStopCatalog;
use App\Queries\Workflow\WorkflowRecordModuleVisibilityStops;
use Closure;
use Database\Seeders\WorkflowApplicationModules;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールの定義の読み取りの口(docs の 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3 の表の
 * RecordModuleDirectory の 3 行): 業務モジュールの一覧・表示の止め・項目と選択肢の名前。主体の判定をしない(read を誰にも許さない業務モジュール
 * でも同じに読む)。レコードを返さない。
 */
final class RecordModuleDirectoryTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    public function test_the_top_level_modules_are_in_the_order_of_the_referable_modules_including_stopped_ones(): void
    {
        $later = $this->recordModule('zz_first');
        $later->update(['sort_key' => -2]);
        $earlier = $this->recordModule('aa_second');
        $earlier->update(['sort_key' => -1]);
        $sameKey = $this->recordModule('ab_third');
        $sameKey->update(['sort_key' => -1]);
        $this->detailsField($earlier, 'tasks');
        $this->recordModule('secrets', []);

        $modules = $this->directory()->topLevelModules();

        $codes = array_column($modules, 'code');
        $this->assertSame(['zz_first', 'aa_second', 'ab_third'], array_slice($codes, 0, 3));
        $this->assertNotContains('tasks_rows', $codes);
        $this->assertContains('secrets', $codes);
        $this->assertContains(WorkflowApplicationModules::PLAN, $codes);
        $this->assertSame(app(RecordAdminQuery::class)->fieldForm('secrets', null)['referableModules'], $modules);
        $this->assertSame(['code' => 'zz_first', 'name' => 'zz_first'], $modules[0]);
    }

    public function test_the_stop_reason_is_the_first_reason_in_the_registration_order_through_the_catalog(): void
    {
        $project = $this->recordModule('stopped');
        $other = $this->recordModule('stopped_later');
        $this->recordModule('open');
        $this->register('test.stops.0', [$other->id => '1 番目']);
        $this->register('test.stops.1', [$project->id => '2 番目', $other->id => '2 番目']);
        $this->register('test.stops.2', [$project->id => '3 番目']);

        foreach (['stopped' => '2 番目', 'stopped_later' => '1 番目', 'open' => null] as $code => $reason) {
            $moduleId = RecordModule::query()->where('code', $code)->sole()->id;

            $this->assertSame($reason, $this->directory()->visibilityStopReason($code), $code);
            $this->assertSame(app(RecordModuleVisibilityStopCatalog::class)->stopReason($moduleId), $reason, $code);
        }

        $this->assertSame(
            WorkflowRecordModuleVisibilityStops::REASON,
            $this->directory()->visibilityStopReason(WorkflowApplicationModules::PLAN),
        );
    }

    public function test_the_field_names_follow_the_definition_order_with_options_and_one_level_of_detail_fields(): void
    {
        $module = $this->recordModule('secrets', []);
        $this->choiceField($module, 'status', ['open', 'done'], ['sort_key' => 2]);
        $title = $this->field($module, 'title', attributes: ['sort_key' => 1]);
        $this->field($module, 'members', RecordFieldType::User, ['sort_key' => 3, 'is_multiple' => true]);
        $tasks = $this->detailsField($module, 'tasks', ['sort_key' => 4]);
        $this->field($this->childOf($tasks), 'task_name', attributes: ['sort_key' => 1]);
        $this->choiceField($this->childOf($tasks), 'priority', ['high', 'low']);
        Record::factory()->create(['record_module_id' => $module->id, 'field_values' => [$title->id => 'レコードの値']]);
        $leaf = fn (string $code, string $type, array $options = [], bool $multiple = false) => [
            'code' => $code, 'name' => $code, 'type' => $type, 'isMultiple' => $multiple,
            'options' => $options, 'fields' => [],
        ];
        $option = fn (string $code) => ['code' => $code, 'name' => "{$code}の名称"];

        $names = $this->directory()->fieldNames('secrets');

        $this->assertSame([
            $leaf('title', 'string'),
            $leaf('status', 'choice', [$option('open'), $option('done')]),
            $leaf('members', 'user', [], true),
            [...$leaf('tasks', 'details'), 'fields' => [
                $leaf('priority', 'choice', [$option('high'), $option('low')]),
                $leaf('task_name', 'string'),
            ]],
        ], $names);
        $this->assertStringNotContainsString('レコードの値', (string) json_encode($names, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readers(): array
    {
        return ['表示の止め' => ['visibilityStopReason'], '項目と選択肢の名前' => ['fieldNames']];
    }

    #[DataProvider('readers')]
    public function test_a_missing_or_details_module_is_not_found(string $method): void
    {
        $tasks = $this->detailsField($this->recordModule('projects'), 'tasks');

        foreach (['missing', $this->childOf($tasks)->code] as $code) {
            try {
                $this->directory()->{$method}($code);
                $this->fail("404 にならない: {$code}");
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_reads_without_writes_or_locks(): void
    {
        $module = $this->recordModule('projects');
        $this->choiceField($module, 'status', ['open']);
        $statements = $this->statementsOf(function (): void {
            $this->directory()->topLevelModules();
            $this->directory()->visibilityStopReason('projects');
            $this->directory()->fieldNames('projects');
        });

        $this->assertNotSame([], $statements);

        foreach ($statements as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(select|with)\b/', $sql);
            $this->assertStringNotContainsString('for update', $sql);
            $this->assertStringNotContainsString('for share', $sql);
            $this->assertStringNotContainsString('pg_advisory', $sql);
        }
    }

    /**
     * 止めの実装を 1 つ、起動と同じタグで登録の順の後ろに足す(起動が登録したワークフローの実装が先頭)。
     *
     * @param  array<int, string>  $reasons  業務モジュールの id → 理由
     */
    private function register(string $name, array $reasons): void
    {
        $this->app->instance($name, new class($reasons) implements RecordModuleVisibilityStops
        {
            /**
             * @param  array<int, string>  $reasons
             */
            public function __construct(private array $reasons) {}

            public function stopReason(int $recordModuleId): ?string
            {
                return $this->reasons[$recordModuleId] ?? null;
            }
        });
        $this->app->tag([$name], RecordModuleVisibilityStops::TAG);
    }

    /**
     * @return list<string> 呼んだ後までに流れた SQL(小文字)
     */
    private function statementsOf(Closure $action): array
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });
        $action();

        return $statements;
    }

    private function directory(): RecordModuleDirectory
    {
        return app(RecordModuleDirectory::class);
    }
}
