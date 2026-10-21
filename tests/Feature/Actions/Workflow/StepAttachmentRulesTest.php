<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\CaseAttachmentRules;
use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Upload\Api\UploadLimits;
use Illuminate\Http\UploadedFile;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesStepAttachments;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの処理の添付の、引戻しのノード・巻き戻り・不変条件(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 2 節の「引戻しのノード」、
 * 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 9.1 節・9.2 節): 引戻す先が申請のノードなら申請の系統(ステップを見ない)、承認のノードなら
 * その写した設定。口の中の失敗は処理ごと巻き戻る。処理は一時保存の添付の行を動かさず、差戻し・引戻しは付いている添付を動かさない。
 */
final class StepAttachmentRulesTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesStepAttachments;

    private const NOT_ATTACHABLE = ['attachments' => CaseAttachmentRules::NOT_ATTACHABLE];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAttachments();
    }

    /**
     * 申請者が申請のノードへ引戻す: 承認のノードが「追加できない」でも付き、契機のステップ名は申請のノード。案件に写したフローの値が偽なら 422。
     */
    public function test_a_pull_back_to_the_apply_node_does_not_read_the_step_setting(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->applyAs($u0001);
        $this->caseNode($case, 'approval1')->update(['prevents_attachment_add' => true]);

        $this->step('pull_back', $u0001, $case, [$this->uploadedFile('引戻し.pdf')]);

        $this->assertSame([$u0001->id, null, '引戻し', '申請', 1], $this->latestRevision($case));
        $other = $this->applyAs($u0001);
        $other->update(['is_attachment_enabled' => false]);
        $pullBack = fn () => $this->step('pull_back', $u0001, $other, [$this->uploadedFile('偽.pdf')]);
        $this->assertSame(self::NOT_ATTACHABLE, $this->validationErrors($pullBack));
    }

    /**
     * 承認のノードへ引戻す: 引戻す先(承認1)の写した設定が「追加できない」なら 422(次の承認2の設定は読まない)。
     */
    public function test_a_pull_back_to_an_approval_node_reads_the_setting_of_that_node(): void
    {
        [$case, $u0002] = $this->caseReadyFor('pull_back');
        $this->caseNode($case, 'approval1')->update(['prevents_attachment_add' => true]);
        $before = [$this->workflowCounts(), $this->nodeStates($case), $this->revisionOf($case)];

        $pullBack = fn () => $this->step('pull_back', $u0002, $case, [$this->uploadedFile('a.pdf')]);

        $this->assertSame(self::NOT_ATTACHABLE, $this->validationErrors($pullBack));
        $this->assertSame($before, [$this->workflowCounts(), $this->nodeStates($case), $this->revisionOf($case)]);
        $this->caseNode($case, 'approval1')->update(['prevents_attachment_add' => false]);
        $this->caseNode($case, 'approval2')->update(['prevents_attachment_add' => true]);
        $pullBack();
        $this->assertSame([[$u0002->id, $u0002->id]], $this->attachedBy($case));
    }

    /**
     * 最後の承認で案件が完了する処理でも付く(口は完了を書く前に呼ぶので、持ち主の鍵を確かめる時に案件はまだ未完了)。版は 1 つで、
     * 完了の履歴の行がその版を指す。
     */
    public function test_the_last_approval_completes_the_case_with_files(): void
    {
        [$case, $u0002] = $this->caseReadyFor('approve');
        $this->step('approve', $u0002, $case);
        $u0003 = $this->seededUser('u0003');

        $result = $this->step('approve', $u0003, $case, [$this->uploadedFile('最後.pdf')]);

        $this->assertSame([WorkflowCaseState::Completed, 2], [$case->refresh()->state, $this->revisionCount($case)]);
        $this->assertSame([$u0003->id, null, '承認', '承認2', 1], $this->latestRevision($case));
        $this->assertSame($this->latestRevisionId($case), $this->historyRevisionId($result));
    }

    /**
     * 口の中の失敗(合計の上限・ファイルの誤り)は外側ごと巻き戻る: 履歴・ノード・案件・版・レコードの添付・実体は前のまま。
     */
    public function test_a_failure_inside_the_record_port_rolls_back_the_step(): void
    {
        [$case, $u0002] = $this->caseReadyFor('approve');
        $snapshot = fn () => [
            $this->workflowCounts(), $this->nodeStates($case), $this->revisionOf($case), $this->revisionCount($case),
            RecordAttachment::query()->count(), Upload::query()->count(), $this->storedFileCount(),
        ];
        $before = $snapshot();
        $this->limitUploads(total: (int) Upload::query()->whereNull('purged_at')->sum('size') + 10);
        $approve = fn (UploadedFile $file) => fn () => $this->step('approve', $u0002, $case, [$file]);
        $errors = $this->validationErrors($approve($this->uploadedFile('合計.pdf')));
        $this->assertSame(['attachments' => UploadLimits::TOTAL_EXCEEDED], $errors);
        $this->assertSame($before, $snapshot());

        $this->limitUploads(file: 100, request: 10_000);
        $errors = $this->validationErrors($approve($this->pdfOfSize('大.pdf', 101)));
        $this->assertSame(['attachments.0'], array_keys($errors));
        $this->assertSame($before, $snapshot());
    }

    /**
     * 処理は一時保存の添付の行を動かさない。差戻し・引戻しは、付いている添付を外さず移さない(足すだけ)。
     */
    public function test_steps_keep_the_draft_rows_and_the_attached_files(): void
    {
        $u0001 = $this->seededUser('u0001');
        $draft = $this->draftWith($u0001, [$this->uploadedFile('下書き.pdf')]);
        $draftRows = $this->draftRows($draft);
        $case = $this->applyWith($u0001, [$this->uploadedFile('申請.pdf')]);
        [$applied] = $this->attachedIds($case);

        $this->step('send_back', $this->seededUser('u0002'), $case, [$this->uploadedFile('差戻し.pdf')]);
        $afterSendBack = $this->attachedIds($case);
        $this->step('pull_back', $this->seededUser('u0002'), $case);

        $this->assertSame([$applied, $afterSendBack[1]], $afterSendBack);
        $this->assertSame($afterSendBack, RecordAttachment::query()->where('record_id', $case->record_id)->orderBy('id')
            ->get()->map(fn (RecordAttachment $row) => [$row->id, $row->upload_id])->values()->all());
        $this->assertSame($draftRows, $this->draftRows(WorkflowCase::query()->findOrFail($draft->id)));
    }

    /**
     * @return list<array{int, int}> レコードの添付の [行の id, 実体の id](付けた順)
     */
    private function attachedIds(WorkflowCase $case): array
    {
        return array_values(RecordAttachment::query()->where('record_id', $case->record_id)->orderBy('id')->get()
            ->map(fn (RecordAttachment $row) => [$row->id, $row->upload_id])->all());
    }
}
