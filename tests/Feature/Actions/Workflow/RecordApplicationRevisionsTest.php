<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\DeleteRecord;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use App\Queries\Workflow\WorkflowRecordHolds;
use Tests\Support\AppliesFromRecords;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 既存のデータからの申請の更新の口と版(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 4 節の 2・3、6 節、8 節):
 * 値を変えなくても版を 1 つ書き、履歴の行がその版を指す。申請のコミットの後は業務の画面から更新・削除できない。開いた後に業務の画面で更新されて
 * いれば 422 record で何も書かない。完了の後にもう一度申請でき、1 つ目の申請の写しは 1 つ目の版のまま。
 */
final class RecordApplicationRevisionsTest extends TransactionalTestCase
{
    use AppliesFromRecords;
    use CatchesValidationErrors;

    private const STALE = 'このレコードは、開いた後に更新されています。画面を開き直してから、もう一度保存してください。';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    /**
     * 値を変えずに申請しても版が 1 つ増え、履歴の行の record_revision_id がその版を指す。版の無いレコード(ファクトリ)でも同じ。
     */
    public function test_an_application_without_changes_writes_one_revision_that_the_history_points_at(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $unchanged = new ApplicationInput(null, null, new RecordInput($this->projectInput()->record->values));
        $bare = Record::factory()->create(['record_module_id' => $this->moduleIdOf(self::PROJECT)]);

        $case = $this->applyFromRecord($u0001, $record->id, $unchanged);
        $fromBare = $this->applyFromRecord($u0001, $bare->id);

        $this->assertCount(2, $this->revisionIds($record->id));
        $this->assertSame([$this->revisionIds($record->id)[1]], $this->historyRevisionIds($case));
        $this->assertCount(1, $this->revisionIds($bare->id));
        $this->assertSame($this->revisionIds($bare->id), $this->historyRevisionIds($fromBare));
    }

    /**
     * 申請のコミットの後、業務の画面からの更新と削除は持ち主の規則で 422 record。
     */
    public function test_after_the_application_the_screen_cannot_update_or_delete_the_record(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $this->applyFromRecord($u0001, $record->id);
        $held = ['record' => WorkflowRecordHolds::REASON];

        $updating = fn () => $this->updateFromScreen($u0001, $record->id, ['summary' => '画面']);
        $this->assertSame($held, $this->validationErrors($updating));
        $deleting = fn () => app(DeleteRecord::class)->execute($this->contextOf($u0001), self::PROJECT, $record->id);
        $this->assertSame($held, $this->validationErrors($deleting));
    }

    /**
     * 開いた後に業務の画面で更新されていれば 422 record で、申請の行も書かれない。業務の画面で変えた項目と足した明細の行は残る。
     * updated_at の無いレコード(版が空文字)でも同じ。
     */
    public function test_a_record_updated_after_it_was_opened_is_rejected_and_nothing_is_written(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $opened = $record->version();
        $bare = Record::factory()->create(['record_module_id' => $this->moduleIdOf(self::PROJECT)]);
        Record::query()->whereKey($bare->id)->update(['updated_at' => null]);
        $this->travel(1)->seconds();
        $rows = [
            new RecordDetailRowInput($this->detailRowIds($record->id)[0]),
            new RecordDetailRowInput(values: ['name' => '追加', 'due_date' => '2026-12-01']),
        ];
        $this->updateFromScreen($u0001, $record->id, ['summary' => '画面で更新'], ['milestones' => $rows]);
        $this->updateFromScreen($u0001, $bare->id, $this->projectInput()->record->values, version: '');
        $counts = $this->workflowCounts();

        $errors = $this->validationErrors(fn () => $this->applyFromRecord($u0001, $record->id, version: $opened));
        $bareErrors = $this->validationErrors(fn () => $this->applyFromRecord($u0001, $bare->id, version: ''));

        $this->assertSame([['record' => self::STALE], ['record' => self::STALE]], [$errors, $bareErrors]);
        $this->assertSame($counts, $this->workflowCounts());
        $values = [$this->valueOf($record->id, 'summary'), $this->valueOf($record->id, 'name')];
        $this->assertSame(['画面で更新', '新製品の企画'], $values);
        $this->assertCount(2, $this->detailRowIds($record->id));
    }

    /**
     * 入力 record_version の null は空文字として比べる(この経路は版を必ず比べる)。
     */
    public function test_a_null_version_is_compared_as_an_empty_version(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $apply = fn () => app(ApplyCase::class)->executeFromRecord(
            $this->contextOf($u0001), self::FLOW_CODE, $record->id, null, $this->projectInput(),
            $this->operationDay()->toDateString(),
        );

        $this->assertSame(['record' => self::STALE], $this->validationErrors($apply));
    }

    /**
     * 申請の入力にキーの無い項目と明細は今のまま(業務データの入れ物の更新の口の規則)。
     */
    public function test_fields_and_detail_rows_not_in_the_input_stay(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001, ['summary' => '概要']);
        $rowIds = $this->detailRowIds($record->id);

        $input = new ApplicationInput(null, null, new RecordInput(['status' => 'active']));
        $this->applyFromRecord($u0001, $record->id, $input);

        $this->assertSame(['active', '概要', '新製品の企画'], [
            $this->valueOf($record->id, 'status'),
            $this->valueOf($record->id, 'summary'),
            $this->valueOf($record->id, 'name'),
        ]);
        $this->assertSame($rowIds, $this->detailRowIds($record->id));
    }

    /**
     * 完了の後にもう一度申請でき、2 つ目の申請の履歴の行は新しい版を指す。1 つ目の申請の写しは 1 つ目の版のまま。
     */
    public function test_a_completed_record_can_be_applied_again_and_the_first_copy_stays(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $first = $this->applyFromRecord($u0001, $record->id, $this->projectInput(['name' => '1 回目']));
        $this->complete($first);

        $second = $this->applyFromRecord($u0001, $record->id, $this->projectInput(['name' => '2 回目']));

        $firstRevision = $this->historyRevisionIds($first)[0];
        $secondRevision = $this->historyRevisionIds($second)[0];
        $requester = new Requester($u0001->id, null);
        $diff = app(RecordRevisionQuery::class)
            ->diffBetween($requester, (int) $firstRevision, (int) $secondRevision, $this->operationDay(), 'ja');
        $names = array_values(array_filter($diff['fields'], fn (array $field) => $field['code'] === 'name'));
        $revisionIds = $this->revisionIds($record->id);
        $this->assertSame($revisionIds[count($revisionIds) - 1], $secondRevision);
        $changes = array_map(fn (array $field) => [$field['before'], $field['after']], $names);
        $this->assertSame([['1 回目', '2 回目']], $changes);
        $this->assertSame($firstRevision, $this->historyRevisionIds($first)[0]);
    }

    /**
     * 完了した申請のあるレコードは業務の画面から消せず(ほかのデータからの参照の文言)、案件を削除した後は消せる。
     */
    public function test_a_record_with_a_completed_case_is_deleted_only_after_the_case_is_deleted(): void
    {
        $u0001 = $this->seededUser('u0001');
        $record = $this->projectRecord($u0001);
        $case = $this->applyFromRecord($u0001, $record->id);
        $this->complete($case);
        $deleting = fn () => app(DeleteRecord::class)->execute($this->contextOf($u0001), self::PROJECT, $record->id);

        $errors = $this->validationErrors($deleting);
        WorkflowCase::query()->whereKey($case->id)->delete();
        $deleting();

        $this->assertSame(['record' => 'このレコードはほかのデータから参照されているため削除できません'], $errors);
        $this->assertFalse(Record::query()->whereKey($record->id)->exists());
    }
}
