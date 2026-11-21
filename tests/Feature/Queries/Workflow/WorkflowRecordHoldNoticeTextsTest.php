<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\RecordRevisionWriter;
use App\Enums\Records\RecordRevisionAction;
use App\Enums\Workflow\WorkflowAction;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Records\Api\RecordHoldLink;
use App\Queries\Workflow\CaseNumbers;
use App\Queries\Workflow\WorkflowRecordHoldNotices;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 持ち主の案内のワークフローの実装のうち、判定しない 2 つ(docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md の 4 節の「持ち主の案内の実装」の
 * revisionLinks・attachmentDisableNotice)。判定を求める者を受けず、案件の表を 1 回だけ読む。stateOf は {@see WorkflowRecordHoldNoticesTest}。
 */
final class WorkflowRecordHoldNoticeTextsTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;

    private const ATTACHMENT_NOTICE = '添付ファイルを追加・削除できる進行中の申請が %d 件あります。解除すると、これらの申請の再申請と処理でも添付ファイルを追加・削除できなくなります。';

    /**
     * 履歴の行(申請・再申請・添付を付けた処理)が指す版だけにリンクを返す(判定しない = 関わらない人の問いでも同じ)。添付を変えない処理の行・
     * ほかのレコードの版・渡していない版は入らない。1 回の問い合わせで、空なら問い合わせない。
     */
    public function test_revision_links_point_at_the_case_of_the_history_rows(): void
    {
        [$a, $b] = $this->seededUsers('u0001', 'u0002');
        $case = $this->activeCase($this->flow(), $a);
        $recordId = (int) $case->record_id;
        [$apply, $approve] = $this->caseNodes($case, 2);
        $applied = (int) $this->process($apply, $a, WorkflowAction::Apply)->record_revision_id;
        $reapplied = (int) $this->process($apply, $a, WorkflowAction::Reapply)->record_revision_id;
        $plainApprove = $this->process($approve, $b, WorkflowAction::Approve);
        $attached = (int) app(RecordRevisionWriter::class)
            ->write($recordId, RecordRevisionAction::Update, null, new RecordRevisionOrigin('承認', '承認 1'));
        $this->process($approve, $b, WorkflowAction::Approve)->update(['record_revision_id' => $attached]);
        $other = $this->activeCase($this->flow(), $a);
        $otherApplied = (int) $this->process($this->caseNodes($other, 1)[0], $a, WorkflowAction::Apply)
            ->record_revision_id;
        $this->assertNull($plainApprove->record_revision_id);

        $passed = [$applied, $reapplied, $attached, $otherApplied, 999_999_999];
        $link = $this->linkOf($case);

        $expected = [$applied => $link, $reapplied => $link, $attached => $link];
        $this->assertEquals($expected, $this->notices()->revisionLinks($recordId, $passed));
        $this->assertSame([], $this->notices()->revisionLinks($recordId, [$otherApplied]));
        $this->assertSame(1, $this->countQueries(fn () => $this->notices()->revisionLinks($recordId, $passed)));
        $this->assertSame(0, $this->countQueries(fn () => $this->notices()->revisionLinks($recordId, [])));
    }

    /**
     * 数の境(業務モジュールの管理.md の 7 節) = 0 件で null、1 件・2 件で数つきの文。一時保存・完了の申請、フローが添付を使わない進行中の申請と、
     * ほかの業務モジュールの申請は数えない。1 回の問い合わせ。
     */
    public function test_the_attachment_notice_counts_active_cases_that_use_attachments_in_the_module(): void
    {
        [$a] = $this->seededUsers('u0001');
        $flow = $this->flow(attachment: true);
        $moduleId = $flow->workflowContent()->sole()->record_module_id;
        $create = fn (string $state, WorkflowFlow $in) => WorkflowCase::factory()->{$state}()
            ->createOne(['workflow_flow_id' => $in->id, 'user_id' => $a->id]);
        $create('draft', $flow);
        $create('completed', $flow);
        $create('active', $this->flow(attachment: true));
        $withoutAttachment = $create('active', $flow);
        $withoutAttachment->update(['is_attachment_enabled' => false]);

        $this->assertNull($this->notices()->attachmentDisableNotice($moduleId));

        $create('active', $flow);
        $this->assertSame(sprintf(self::ATTACHMENT_NOTICE, 1), $this->notices()->attachmentDisableNotice($moduleId));

        $create('active', $flow);
        $this->assertSame(sprintf(self::ATTACHMENT_NOTICE, 2), $this->notices()->attachmentDisableNotice($moduleId));
        $this->assertSame(1, $this->countQueries(fn () => $this->notices()->attachmentDisableNotice($moduleId)));
    }

    private function notices(): WorkflowRecordHoldNotices
    {
        return app(WorkflowRecordHoldNotices::class);
    }

    private function linkOf(WorkflowCase $case): RecordHoldLink
    {
        return new RecordHoldLink(
            CaseNumbers::format((int) $case->case_number),
            route('workflow.cases.show', ['case' => $case->id]),
        );
    }

    private function activeCase(WorkflowFlow $flow, User $applicant): WorkflowCase
    {
        return WorkflowCase::factory()->active()
            ->createOne(['workflow_flow_id' => $flow->id, 'user_id' => $applicant->id]);
    }

    private function countQueries(callable $action): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /**
     * フロー(添付を使うか)。
     */
    private function flow(bool $attachment = false): WorkflowFlow
    {
        return WorkflowFlow::factory()->create(['is_attachment_enabled' => $attachment]);
    }
}
