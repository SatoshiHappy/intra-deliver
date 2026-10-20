<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecordField;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 更新履歴の版と定義の変更(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 1 節・2 節・5.2 節・9 節の「定義の写し」):
 * 定義の変更では版を作らず、変更の後の値を変えない業務の画面の保存も版を作らない。契機のある書き込みはいつも版を作り、その時の定義を写す。
 * 過去の版どうしの差分は、その時の名前で出て、後で消した項目の変化も出る。
 */
final class RecordRevisionDefinitionTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    private RecordModule $module;

    private RecordField $status;

    private RecordField $tasks;

    protected function setUp(): void
    {
        parent::setUp();
        $customers = $this->recordModule('customers');
        $this->module = $this->recordModule('projects');
        $this->field($this->module, 'title', attributes: ['sort_key' => 1, 'is_multiline' => true]);
        $this->field($this->module, 'memo', attributes: ['sort_key' => 2]);
        $this->status = $this->choiceField($this->module, 'status', ['open', 'done'], ['sort_key' => 3]);
        $this->field($this->module, 'members', RecordFieldType::User, ['sort_key' => 4, 'is_multiple' => true]);
        $this->referenceField($this->module, 'customer', $customers, ['sort_key' => 5]);
        $this->tasks = $this->detailsField($this->module, 'tasks', ['sort_key' => 6]);
        $this->field($this->childOf($this->tasks), 'task_name');
        $this->choiceField($this->childOf($this->tasks), 'phase', ['early', 'late']);
    }

    public function test_the_labels_hold_every_field_and_the_selected_options(): void
    {
        $customer = Record::factory()->create(['record_module_id' => $this->moduleId('customers')]);

        $values = ['title' => '企画', 'status' => 'open', 'customer' => $customer->id];
        $recordId = $this->create($values, [['phase' => 'late']]);

        $labels = $this->latest($recordId)->labels;
        $childFields = $labels['fields'][$this->tasks->id]['fields'] ?? [];
        $this->assertEqualsCanonicalizing(
            ['title', 'memo', 'status', 'members', 'customer', 'tasks'],
            array_column($labels['fields'] ?? [], 'code'),
        );
        $title = $labels['fields'][$this->fieldId('title')] ?? [];
        ksort($title);
        $this->assertSame(
            ['code' => 'title', 'multiline' => true, 'name' => 'title', 'sort_key' => 1, 'type' => 'string'],
            $title,
        );
        $this->assertSame([true, 'customers'], [
            $labels['fields'][$this->fieldId('members')]['multiple'] ?? null,
            $labels['fields'][$this->fieldId('customer')]['referenced_module'] ?? null,
        ]);
        $this->assertEqualsCanonicalizing(['task_name', 'phase'], array_column($childFields, 'code'));
        $this->assertSame([
            $this->status->id => ['open' => 'openの名称'],
            $this->fieldId('phase') => ['late' => 'lateの名称'],
        ], $labels['options'] ?? null);
    }

    public function test_definition_changes_write_no_revision_and_an_unchanged_save_after_them_writes_none(): void
    {
        $recordId = $this->create(['title' => '企画', 'memo' => 'メモ'], [['task_name' => '設計']]);
        $this->field($this->module, 'urgent', RecordFieldType::Boolean);
        $this->detailsField($this->module, 'notes');
        $this->status->update(['name' => '状況']);
        app(DeleteRecordField::class)->execute('projects', 'customer');
        app(DeleteRecordField::class)->execute('projects', 'tasks');
        app(DeleteRecordField::class)->execute('projects', 'memo');

        $this->update($recordId, ['title' => '企画']);

        $this->assertSame(1, RecordRevision::query()->where('record_id', $recordId)->count());
        $values = Record::query()->findOrFail($recordId)->field_values ?? [];
        $this->assertFalse($values[$this->fieldId('urgent')] ?? null);
    }

    public function test_an_unchanged_save_after_adding_a_boolean_to_the_details_writes_false_but_no_revision(): void
    {
        $recordId = $this->create(['title' => '企画'], [['task_name' => '設計'], ['task_name' => '実装']]);
        [$first, $second] = $this->rowIds($recordId);
        $done = $this->field($this->childOf($this->tasks), 'done', RecordFieldType::Boolean);

        // 並べ替えだけで送ると行が書き直され、足した真偽の項目に偽が入る(値は変えていない)
        $this->update($recordId, [], [
            new RecordDetailRowInput($second, ['task_name' => '実装']),
            new RecordDetailRowInput($first, ['task_name' => '設計']),
        ]);

        $this->assertSame(1, RecordRevision::query()->where('record_id', $recordId)->count());
        $doneOf = fn (int $rowId) => Record::query()->findOrFail($rowId)->field_values[$done->id] ?? null;
        $this->assertSame([false, false], [$doneOf($first), $doneOf($second)]);
    }

    public function test_a_write_with_an_origin_copies_the_definition_and_the_row_order_of_the_time(): void
    {
        $recordId = $this->create(['title' => '企画', 'memo' => 'メモ'], [['task_name' => '設計'], ['task_name' => '実装']]);
        [$first, $second] = $this->rowIds($recordId);
        app(DeleteRecordField::class)->execute('projects', 'memo');
        $rows = [
            new RecordDetailRowInput($second, ['task_name' => '実装']),
            new RecordDetailRowInput($first, ['task_name' => '設計']),
        ];

        $this->update($recordId, [], $rows, new RecordRevisionOrigin('再申請', '申請'));

        $latest = $this->latest($recordId);
        $this->assertSame(
            [2, '再申請', '申請'],
            [$latest->revision, $latest->origin_operation, $latest->origin_node_name],
        );
        $this->assertNotContains('memo', array_column($latest->labels['fields'] ?? [], 'code'));
        $this->assertSame([$second, $first], array_column($latest->snapshot['details'][$this->tasks->id] ?? [], 'id'));
    }

    public function test_the_first_edit_after_deleting_a_field_does_not_show_the_deleted_field(): void
    {
        $recordId = $this->create(['title' => '企画', 'memo' => 'メモ']);
        app(DeleteRecordField::class)->execute('projects', 'memo');

        $this->update($recordId, ['title' => '改訂']);

        $this->assertSame(['title'], array_column($this->diffOfLastTwo($recordId)['fields'], 'code'));
    }

    public function test_earlier_revisions_compare_with_the_names_of_the_time_and_keep_a_field_deleted_later(): void
    {
        $recordId = $this->create(['status' => 'open', 'memo' => '前']);
        $this->update($recordId, ['status' => 'done', 'memo' => '後']);
        $this->status->update(['name' => '状況']);
        RecordFieldOption::query()->where('record_field_id', $this->status->id)->update(['name' => '今の名前']);
        app(DeleteRecordField::class)->execute('projects', 'memo');

        $diff = $this->diffOfLastTwo($recordId);

        $this->assertSame([
            ['code' => 'memo', 'name' => 'memo', 'before' => '前', 'after' => '後'],
            [
                'code' => 'status', 'name' => 'status',
                'before' => ['code' => 'open', 'name' => 'openの名称'],
                'after' => ['code' => 'done', 'name' => 'doneの名称'],
            ],
        ], $diff['fields']);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>  $rows  明細 tasks の新しい行
     */
    private function create(array $values, array $rows = []): int
    {
        $details = ['tasks' => array_map(fn (array $row) => new RecordDetailRowInput(null, $row), $rows)];

        return app(CreateRecord::class)->execute($this->contextFor(), 'projects', new RecordInput($values, $details));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<RecordDetailRowInput>|null  $rows
     */
    private function update(
        int $recordId,
        array $values,
        ?array $rows = null,
        ?RecordRevisionOrigin $origin = null,
    ): void {
        $input = new RecordInput($values, $rows === null ? [] : ['tasks' => $rows]);
        $version = Record::query()->findOrFail($recordId)->version();
        $context = $this->contextFor();
        app(UpdateRecord::class)->execute($context, 'projects', $recordId, $input, $version, origin: $origin);
    }

    private function latest(int $recordId): RecordRevision
    {
        return RecordRevision::query()->where('record_id', $recordId)->orderByDesc('revision')->firstOrFail();
    }

    /**
     * 最新の版とその 1 つ前の版の差分。
     *
     * @return array<string, mixed>
     */
    private function diffOfLastTwo(int $recordId): array
    {
        $revisions = RecordRevision::query()->where('record_id', $recordId)->orderByDesc('revision')->limit(2);
        [$after, $before] = $revisions->pluck('id')->all();
        $date = CarbonImmutable::parse(self::TODAY);

        return app(RecordRevisionQuery::class)->diffBetween(new Requester(null, null), $before, $after, $date, 'ja');
    }

    /**
     * @return list<int>
     */
    private function rowIds(int $recordId): array
    {
        $rows = Record::query()->where('parent_record_id', $recordId)->orderBy('sort_key');

        return array_values($rows->pluck('id')->all());
    }

    private function fieldId(string $code): int
    {
        $moduleIds = [$this->module->id, $this->tasks->child_record_module_id];

        return RecordField::query()->whereIn('record_module_id', $moduleIds)->where('code', $code)->soleValue('id');
    }

    private function moduleId(string $code): int
    {
        return RecordModule::query()->where('code', $code)->soleValue('id');
    }
}
