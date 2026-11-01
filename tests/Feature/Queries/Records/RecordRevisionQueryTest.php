<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Models\Records\Record;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use App\Queries\Records\RecordRevisionDiff;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 更新履歴の版の読み取りの公開の口(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.1 節の表): 最新の版の id と、その版より後の版の有無と、
 * 同じレコードの版 2 つの差分(内部の 1 か所 {@see RecordRevisionDiff::between()})。無いレコード・明細の行・無い版・別のレコードの版は 404。
 * 版の写しの形は RecordRevisionViewTest。
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

    public function test_a_revision_after_the_given_one_exists_only_before_the_latest(): void
    {
        $recordId = $this->create('企画');
        $this->update($recordId, '改訂');
        [$first, $latest] = $this->revisionIds($recordId);
        [$other] = $this->revisionIds($this->create('ほか'));

        $this->assertSame([true, false, false], [
            $this->revisionQuery()->hasRevisionAfter($first),
            $this->revisionQuery()->hasRevisionAfter($latest),
            $this->revisionQuery()->hasRevisionAfter($other),
        ]);
        $missing = fn () => $this->revisionQuery()->hasRevisionAfter(999_999_999);
        $this->assertThrows($missing, ModelNotFoundException::class);
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

        return app(RecordRevisionDiff::class)->between(new Requester(null, null), $before, $after, $date, 'ja');
    }

    private function create(string $title): int
    {
        return app(CreateRecord::class)->execute($this->contextFor(), 'projects', new RecordInput(['title' => $title]));
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
