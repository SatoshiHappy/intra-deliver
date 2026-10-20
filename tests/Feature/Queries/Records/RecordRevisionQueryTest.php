<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecordField;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 更新履歴の版の読み取りの公開の口(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.1 節の表): 最新の版の id と、
 * 同じレコードの版 2 つの差分。無いレコード・明細の行・無い版・別のレコードの版は 404。
 */
final class RecordRevisionQueryTest extends TransactionalTestCase
{
    use BuildsRecordModules;

    protected function setUp(): void
    {
        parent::setUp();
        $module = $this->recordModule('projects');
        $this->field($module, 'title');
        $tasks = $this->detailsField($module, 'tasks');
        $this->field($this->childOf($tasks), 'task_name');
    }

    public function test_the_latest_id_is_the_last_revision_of_the_record_or_null_without_revisions(): void
    {
        $recordId = $this->create('企画');
        $this->update($recordId, '改訂');
        $this->create('ほか');
        $moduleId = Record::query()->findOrFail($recordId)->record_module_id;
        $withoutRevisions = Record::factory()->create(['record_module_id' => $moduleId]);

        $latest = RecordRevision::query()->where('record_id', $recordId)->where('revision', 2)->soleValue('id');
        $this->assertSame($latest, $this->revisionQuery()->latestId($recordId));
        $this->assertNull($this->revisionQuery()->latestId($withoutRevisions->id));
    }

    public function test_the_latest_id_of_a_missing_record_or_a_details_row_is_not_found(): void
    {
        $input = new RecordInput([], ['tasks' => [new RecordDetailRowInput(null, ['task_name' => '設計'])]]);
        $recordId = app(CreateRecord::class)->execute($this->contextFor(), 'projects', $input);
        $rowId = Record::query()->where('parent_record_id', $recordId)->soleValue('id');

        foreach ([999_999_999, $rowId] as $id) {
            $this->assertThrows(fn () => $this->revisionQuery()->latestId($id), ModelNotFoundException::class);
        }
    }

    public function test_diff_between_two_revisions_of_the_record(): void
    {
        $recordId = $this->create('企画');
        $this->update($recordId, '改訂');
        [$before, $after] = $this->revisionIds($recordId);

        $diff = $this->diff($before, $after);

        $this->assertSame(
            [['code' => 'title', 'name' => 'title', 'before' => '企画', 'after' => '改訂']],
            $diff['fields'],
        );
        $this->assertSame([[], []], [$diff['details'], $diff['attachments']]);
    }

    public function test_a_missing_revision_or_revisions_of_different_records_are_not_found(): void
    {
        [$revision] = $this->revisionIds($this->create('企画'));
        [$other] = $this->revisionIds($this->create('ほか'));

        $this->assertThrows(fn () => $this->diff($revision, 999_999_999), ModelNotFoundException::class);
        $this->assertThrows(fn () => $this->diff(999_999_999, $revision), ModelNotFoundException::class);
        $this->assertThrows(fn () => $this->diff($revision, $other), ModelNotFoundException::class);
    }

    public function test_the_diff_on_the_current_definition_drops_fields_whose_code_was_reused(): void
    {
        $module = RecordModule::query()->where('code', 'projects')->sole();
        $child = RecordModule::query()->where('code', 'tasks_rows')->sole();
        $amount = $this->field($module, 'amount', RecordFieldType::Integer);
        $quantity = $this->field($child, 'quantity', RecordFieldType::Integer);
        $rows = [['task_name' => '設計', 'quantity' => 1], ['task_name' => '実装', 'quantity' => 1]];
        $newRows = array_map(fn (array $row) => new RecordDetailRowInput(null, $row), $rows);
        $input = new RecordInput(['title' => '企画', 'amount' => 100], ['tasks' => $newRows]);
        $recordId = app(CreateRecord::class)->execute($this->contextFor(), 'projects', $input);
        $rowIds = Record::query()->where('parent_record_id', $recordId)->orderBy('sort_key')->pluck('id');
        [$design, $build] = $rowIds->all();
        $this->updateWith($recordId, new RecordInput(['title' => '改訂', 'amount' => 200], ['tasks' => [
            new RecordDetailRowInput($design, ['task_name' => '設計', 'quantity' => 2]),
            new RecordDetailRowInput($build, ['task_name' => '構築', 'quantity' => 2]),
        ]]));
        [$before, $after] = $this->revisionIds($recordId);
        // 項目を消して、同じコードで型の違う項目を作り直す(コードは消した後に再利用できる)
        $amount->delete();
        $quantity->delete();
        $this->choiceField($module, 'amount', ['high']);
        $this->choiceField($child, 'quantity', ['many']);
        $guest = new Requester(null, null);
        $date = CarbonImmutable::parse(self::TODAY);

        $all = $this->revisionQuery()->diffBetween($guest, $before, $after, $date, 'ja');
        $current = $this->revisionQuery()->diffOnCurrentDefinition($guest, $before, $after, $date, 'ja');

        $this->assertEqualsCanonicalizing(['title', 'amount'], array_column($all['fields'], 'code'));
        $this->assertSame(['title'], array_column($current['fields'], 'code'));
        $this->assertCount(2, $all['details'][0]['rows'] ?? []);
        $this->assertSame(
            [[$build, ['task_name'], ['task_name' => '実装'], ['task_name' => '構築']]],
            array_map(
                fn (array $row) => [$row['rowId'], $row['changedCodes'], $row['before'], $row['after']],
                $current['details'][0]['rows'] ?? [],
            ),
        );
    }

    public function test_the_diff_on_the_current_definition_drops_a_details_field_with_no_rows_left(): void
    {
        $child = RecordModule::query()->where('code', 'tasks_rows')->sole();
        $quantity = $this->field($child, 'quantity', RecordFieldType::Integer);
        [$recordId, $rowId] = $this->recordWithOneRow(['task_name' => '設計', 'quantity' => 1]);
        $this->updateWith($recordId, new RecordInput([], ['tasks' => [
            new RecordDetailRowInput($rowId, ['task_name' => '設計', 'quantity' => 2]),
        ]]));
        $quantity->delete();
        $this->choiceField($child, 'quantity', ['many']);

        [$all, $current] = $this->bothDiffs($recordId);

        $this->assertSame(['tasks'], array_column($all['details'], 'code'));
        $this->assertSame([[], []], [$current['fields'], $current['details']]);
    }

    public function test_the_diff_on_the_current_definition_drops_a_details_field_recreated_with_the_same_code(): void
    {
        [$recordId, $rowId] = $this->recordWithOneRow(['task_name' => '設計']);
        $this->updateWith($recordId, new RecordInput([], ['tasks' => [
            new RecordDetailRowInput($rowId, ['task_name' => '基本設計']),
        ]]));
        app(DeleteRecordField::class)->execute('projects', 'tasks');
        $tasks = $this->detailsField(RecordModule::query()->where('code', 'projects')->sole(), 'tasks');
        $this->field($this->childOf($tasks), 'task_name');

        [$all, $current] = $this->bothDiffs($recordId);

        $this->assertSame(['tasks'], array_column($all['details'], 'code'));
        $this->assertSame([], $current['details']);
    }

    /**
     * 明細 tasks に行を 1 つ持つレコード。
     *
     * @param  array<string, mixed>  $row
     * @return array{int, int} レコードの id と行の id
     */
    private function recordWithOneRow(array $row): array
    {
        $input = new RecordInput(['title' => '企画'], ['tasks' => [new RecordDetailRowInput(null, $row)]]);
        $recordId = app(CreateRecord::class)->execute($this->contextFor(), 'projects', $input);

        return [$recordId, Record::query()->where('parent_record_id', $recordId)->soleValue('id')];
    }

    /**
     * 最初の版と最新の版の、全部の差分と今の定義で絞った差分。
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function bothDiffs(int $recordId): array
    {
        $ids = $this->revisionIds($recordId);
        [$before, $after] = [$ids[0], $ids[count($ids) - 1]];
        $guest = new Requester(null, null);
        $date = CarbonImmutable::parse(self::TODAY);

        return [
            $this->revisionQuery()->diffBetween($guest, $before, $after, $date, 'ja'),
            $this->revisionQuery()->diffOnCurrentDefinition($guest, $before, $after, $date, 'ja'),
        ];
    }

    private function revisionQuery(): RecordRevisionQuery
    {
        return app(RecordRevisionQuery::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function diff(int $before, int $after): array
    {
        $date = CarbonImmutable::parse(self::TODAY);

        return $this->revisionQuery()->diffBetween(new Requester(null, null), $before, $after, $date, 'ja');
    }

    private function create(string $title): int
    {
        return app(CreateRecord::class)->execute($this->contextFor(), 'projects', new RecordInput(['title' => $title]));
    }

    private function updateWith(int $recordId, RecordInput $input): void
    {
        $version = Record::query()->findOrFail($recordId)->version();
        app(UpdateRecord::class)->execute($this->contextFor(), 'projects', $recordId, $input, $version);
    }

    private function update(int $recordId, string $title): void
    {
        $version = Record::query()->findOrFail($recordId)->version();
        $input = new RecordInput(['title' => $title]);
        app(UpdateRecord::class)->execute($this->contextFor(), 'projects', $recordId, $input, $version);
    }

    /**
     * @return list<int>
     */
    private function revisionIds(int $recordId): array
    {
        $revisions = RecordRevision::query()->where('record_id', $recordId)->orderBy('revision');

        return array_values($revisions->pluck('id')->all());
    }
}
