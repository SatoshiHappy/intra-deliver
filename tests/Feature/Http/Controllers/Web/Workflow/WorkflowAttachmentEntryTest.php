<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use App\Models\Workflow\WorkflowCaseNode;
use Carbon\CarbonImmutable;
use Database\Seeders\SeedingDates;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 添付を受けるワークフローの口(docs の 設計/ワークフロー/申請の周辺/画面.md の 1 節の表)が、multipart の要求(envelope + attachments[] =
 * 設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節)を JSON の要求と同じ入力として業務処理へ渡し、添付が付くこと。一時保存の上書きは PUT の
 * multipart、一時保存の口は外す指定も受ける。初期データ(案件の見本)を入れ、ルートの認可を外さない。
 */
final class WorkflowAttachmentEntryTest extends TransactionalTestCase
{
    use BuildsUploads;
    use SendsAttachmentEnvelopes;
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakeUploadDisk();
        $this->limitUploads();
        // 申請基準日に初期データの日付を送るので、今日を初期データの流す日に固定する(WorkflowWriteDestinationTest と同じ)
        CarbonImmutable::setTestNow(SeedingDates::BASE.' 03:00:00 UTC');
    }

    public function test_a_new_application_and_a_new_draft_take_the_files(): void
    {
        $this->actingAsUser('u0001');
        $files = [$this->uploadedFile('見積書.pdf'), $this->uploadedFile('図.png', 'png')];
        $input = ['values' => $this->planValues('添付の計画'), 'base_date' => $this->baseDate()];

        $applied = $this->multipart('POST', '/workflow/flows/project_plan/apply', $input, $files)
            ->assertStatus(302)->assertSessionHasNoErrors();
        $drafted = $this->multipart('POST', '/workflow/flows/project_plan/drafts', ['name' => '下書き'], $files)
            ->assertStatus(302)->assertSessionHasNoErrors();

        $caseId = $this->caseIdIn($applied, '#/workflow/cases/(\d+)$#D');
        $draftId = $this->caseIdIn($drafted, '#/workflow/drafts/(\d+)/edit$#D');
        $this->assertSame('プロジェクト計画の承認 添付の計画', WorkflowCase::query()->findOrFail($caseId)->name);
        $this->assertSame(['見積書.pdf', '図.png'], $this->recordAttachmentNames($caseId));
        $this->assertSame(['見積書.pdf', '図.png'], $this->draftAttachmentNames($draftId));
    }

    public function test_the_draft_overwrite_by_put_and_the_apply_take_files_and_removals(): void
    {
        $this->actingAsUser('u0001');
        $draft = $this->seededDraft();
        $put = ['revision' => $this->revisionOf($draft), 'values' => ['title' => '様式の変更']];

        $files = [$this->uploadedFile('a.pdf'), $this->uploadedFile('b.pdf')];

        $this->multipart('PUT', "/workflow/drafts/{$draft}", $put, $files)
            ->assertStatus(303)->assertRedirect("/workflow/drafts/{$draft}/edit")->assertSessionHasNoErrors();
        $this->assertSame(['a.pdf', 'b.pdf'], $this->draftAttachmentNames($draft));
        $first = WorkflowCaseDraftAttachment::query()->where('workflow_case_id', $draft)->orderBy('id')->firstOrFail();

        $apply = [
            'revision' => $this->revisionOf($draft), 'values' => $this->issueValues('請求書の様式の変更'),
            'base_date' => $this->baseDate(), 'remove_attachment_ids' => [$first->id],
        ];
        $this->multipart('POST', "/workflow/drafts/{$draft}/apply", $apply, [$this->uploadedFile('c.pdf')])
            ->assertRedirect("/workflow/cases/{$draft}")->assertSessionHasNoErrors();

        $this->assertSame(['b.pdf', 'c.pdf'], $this->recordAttachmentNames($draft));
        $this->assertSame([], $this->draftAttachmentNames($draft));
    }

    public function test_the_reapplication_takes_the_files(): void
    {
        $this->actingAsUser('u0001');
        $case = $this->caseNumbered(4);
        $before = $this->recordAttachmentNames($case);
        $input = ['revision' => $this->revisionOf($case), 'values' => $this->planValuesOfCase4(['budget' => 120])];

        $this->multipart('POST', "/workflow/cases/{$case}/reapply", $input, [$this->uploadedFile('再申請.pdf')])
            ->assertRedirect("/workflow/cases/{$case}")->assertSessionHasNoErrors();

        $this->assertSame([...$before, '再申請.pdf'], $this->recordAttachmentNames($case));
        $this->assertSame('active', WorkflowCase::query()->findOrFail($case)->state->value);
    }

    /**
     * 処理 6 つ。行 = [操作の URL の末尾, 利用者, 案件番号, 差戻し先の位置, 書いた後の案件の状態]。
     *
     * @return array<string, array{string, string, int, int|null, string}>
     */
    public static function caseActions(): array
    {
        return [
            'approve' => ['approve', 'u0002', 2, null, 'active'],
            'approve early' => ['approve-early', 'u0002', 5, null, 'completed'],
            'reject' => ['reject', 'u0002', 2, null, 'completed'],
            'send back' => ['send-back', 'u0003', 3, 2, 'active'],
            'pull back' => ['pull-back', 'u0002', 3, null, 'active'],
            'withdraw' => ['withdraw', 'u0001', 4, null, 'completed'],
        ];
    }

    #[DataProvider('caseActions')]
    public function test_a_case_action_takes_the_files(
        string $action,
        string $user,
        int $number,
        ?int $sendBackPosition,
        string $state,
    ): void {
        $this->actingAsUser($user);
        $case = $this->caseNumbered($number);
        $before = $this->recordAttachmentNames($case);
        $nodeId = $action === 'pull-back' ? $this->pullBackNodeIdOf($case, $user) : $this->currentNodeIdOf($case);
        $input = [
            'revision' => $this->revisionOf($case), 'comment' => '添付します。', 'node_id' => $nodeId,
            ...($sendBackPosition === null ? [] : ['send_back_node_id' => $this->nodeIdAt($case, $sendBackPosition)]),
        ];

        $this->multipart('POST', "/workflow/cases/{$case}/{$action}", $input, [$this->uploadedFile('根拠.pdf')])
            ->assertRedirect("/workflow/cases/{$case}")->assertSessionHasNoErrors();

        $this->assertSame([...$before, '根拠.pdf'], $this->recordAttachmentNames($case));
        $this->assertSame($state, WorkflowCase::query()->findOrFail($case)->state->value);
    }

    private function nodeIdAt(int $caseId, int $position): int
    {
        return WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('position', $position)->sole()->id;
    }
}
