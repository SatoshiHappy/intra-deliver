<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecord;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordRevisionAction;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 業務の画面の書き込み(契機なし)が書く更新履歴の版(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 1〜3 節・9 節):
 * 登録で版 1、値を変えた更新で次の版、差分(5.2 節)の無い更新では版を作らない。採番は行の鍵の中で、レコードごとに 1 から。
 */
final class RecordRevisionWritesTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    private RecordModule $module;

    private int $titleId;

    private int $tasksId;

    private int $taskNameId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = $this->recordModule('projects');
        $this->titleId = $this->field($this->module, 'title')->id;
        $this->field($this->module, 'members', RecordFieldType::User, ['is_multiple' => true]);
        $tasks = $this->detailsField($this->module, 'tasks');
        $this->taskNameId = $this->field($this->childOf($tasks), 'task_name')->id;
        $this->tasksId = $tasks->id;
    }

    public function test_a_registration_writes_revision_one_and_a_changed_update_the_next(): void
    {
        $user = $this->user();
        $recordId = $this->create(['title' => '企画'], $user);

        $this->update($recordId, ['title' => '改訂'], user: $user);

        $revisions = $this->revisions($recordId);
        $this->assertSame([[1, RecordRevisionAction::Create], [2, RecordRevisionAction::Update]], array_map(
            fn (RecordRevision $revision) => [$revision->revision, $revision->action],
            $revisions,
        ));
        $latest = $revisions[1];
        $this->assertSame(
            [$user->id, null, null, null],
            [$latest->user_id, $latest->principal_user_id, $latest->origin_operation, $latest->origin_node_name],
        );
        $this->assertSame([$this->titleId => '改訂'], $revisions[1]->snapshot['values'] ?? null);
        $types = DB::selectOne(
            "select jsonb_typeof(snapshot->'references') as r, jsonb_typeof(snapshot->'details'->?) as d,"
            ." jsonb_typeof(snapshot->'attachments') as a, jsonb_typeof(labels->'options') as o,"
            ." jsonb_typeof(labels->'fields'->?->'fields') as f from record_revisions where id = ?",
            [(string) $this->tasksId, (string) $this->tasksId, $latest->id],
        );
        $this->assertSame(
            ['object', 'array', 'array', 'object', 'object'],
            [$types->r, $types->d, $types->a, $types->o, $types->f],
        );
    }

    public function test_an_update_that_changes_nothing_in_the_diff_writes_no_revision(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        $details = [['task_name' => '設計'], ['task_name' => '実装']];
        $recordId = $this->create(['title' => '企画', 'members' => [$a->id, $b->id]], details: $details);
        [$first, $second] = $this->rowIds($recordId);

        $this->update($recordId, ['title' => '企画']);
        $this->update($recordId, ['members' => [$b->id, $a->id]]);
        $this->update($recordId, [], [
            new RecordDetailRowInput($second, ['task_name' => '実装']),
            new RecordDetailRowInput($first, ['task_name' => '設計']),
        ]);

        $this->assertCount(1, $this->revisions($recordId));
        $this->assertSame([$second, $first], $this->rowIds($recordId));
    }

    public function test_a_change_of_a_detail_row_goes_into_the_parent_revision(): void
    {
        $recordId = $this->create(['title' => '企画'], details: [['task_name' => '設計']]);
        [$row] = $this->rowIds($recordId);

        $this->update($recordId, [], [new RecordDetailRowInput($row, ['task_name' => '基本設計'])]);

        $revisions = $this->revisions($recordId);
        $this->assertCount(2, $revisions);
        $this->assertSame([], RecordRevision::query()->where('record_id', $row)->pluck('id')->all());
        $rows = $revisions[1]->snapshot['details'][$this->tasksId] ?? [];
        $written = array_map(fn (array $row) => [$row['id'], $row['values'][$this->taskNameId]], $rows);
        $this->assertSame([[$row, '基本設計']], $written);
    }

    public function test_the_first_write_of_a_record_without_revisions_is_update_revision_one(): void
    {
        $record = Record::factory()->create(['record_module_id' => $this->module->id]);

        $this->update($record->id, []);

        $this->assertSame([[1, RecordRevisionAction::Update]], array_map(
            fn (RecordRevision $revision) => [$revision->revision, $revision->action],
            $this->revisions($record->id),
        ));
    }

    public function test_revisions_are_numbered_per_record_under_the_row_lock(): void
    {
        $recordId = $this->create(['title' => '1']);
        $otherId = $this->create(['title' => 'ほか']);
        $this->update($recordId, ['title' => '2']);

        $log = $this->queries(fn () => $this->update($recordId, ['title' => '3']));

        $numbers = fn (int $id) => array_column($this->revisions($id), 'revision');
        $this->assertSame([[1, 2, 3], [1]], [$numbers($recordId), $numbers($otherId)]);
        $locked = $this->firstIndex($log, 'for no key update');
        $numbered = $this->firstIndex($log, 'from "record_revisions"');
        $this->assertLessThan($numbered, $locked, '版の採番の読みは行の鍵の後');
        $this->assertGreaterThanOrEqual(2, $log[$numbered][1], '版の採番は業務処理のトランザクションの中');
    }

    public function test_deleting_a_record_deletes_its_revisions(): void
    {
        $recordId = $this->create(['title' => '消す']);

        app(DeleteRecord::class)->execute($this->contextFor(), 'projects', $recordId);

        $this->assertSame(0, RecordRevision::query()->where('record_id', $recordId)->count());
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>  $details  明細 tasks の新しい行
     */
    private function create(array $values, ?User $user = null, array $details = []): int
    {
        $rows = array_map(fn (array $row) => new RecordDetailRowInput(null, $row), $details);

        $input = new RecordInput($values, ['tasks' => $rows]);

        return app(CreateRecord::class)->execute($this->contextFor($user?->id), 'projects', $input);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<RecordDetailRowInput>|null  $rows  明細 tasks の行(null = 送らない)
     */
    private function update(int $recordId, array $values, ?array $rows = null, ?User $user = null): void
    {
        $input = new RecordInput($values, $rows === null ? [] : ['tasks' => $rows]);
        $version = Record::query()->findOrFail($recordId)->version();
        app(UpdateRecord::class)->execute($this->contextFor($user?->id), 'projects', $recordId, $input, $version);
    }

    /**
     * @return list<RecordRevision>
     */
    private function revisions(int $recordId): array
    {
        return array_values(RecordRevision::query()->where('record_id', $recordId)->orderBy('revision')->get()->all());
    }

    /**
     * @return list<int> 明細の行の id(並び順)
     */
    private function rowIds(int $recordId): array
    {
        $rows = Record::query()->where('parent_record_id', $recordId)->orderBy('sort_key');

        return array_values($rows->pluck('id')->all());
    }

    /**
     * 処理の中で発行された SQL とトランザクションのレベル(返した後に発行された SQL は返した配列に入らない)。
     *
     * @return list<array{string, int}>
     */
    private function queries(Closure $run): array
    {
        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log) {
            $log[] = [$query->sql, $query->connection->transactionLevel()];
        });
        $run();

        return $log;
    }

    /**
     * @param  list<array{string, int}>  $log
     */
    private function firstIndex(array $log, string $needle): int
    {
        foreach ($log as $index => [$sql]) {
            if (str_contains($sql, $needle)) {
                return $index;
            }
        }

        $this->fail("{$needle} を含む SQL が無い");
    }
}
