<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\WithdrawCase;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Workflow\WorkflowRecordHolds;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 承認・承認終了・否認・取止め(docs の 設計/ワークフロー/案件の操作/操作.md 3.6・3.9、同時実行と検査.md 4・6・7 節): 承認で次のノードへ到達し(展開)、最後の
 * 承認で完了(承認)、承認終了・否認・取止めで完了(後のノードは到達しないまま)。コメントの規則、古い改訂番号、展開 0 人、表で許されない人は 422 case・
 * 閲覧できない人は 404 で、どれも何も変わらない。フロー = 申請 → 承認 1(u0002)→ 承認 2(u0003)。
 */
final class ProcessCaseTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsWorkflowOperations;

    private const NOT_PERMITTED = ['case' => 'この申請は今この操作をできません'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    public function test_approving_reaches_the_next_node(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));

        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case, '  確認しました ');

        $case->refresh();
        $this->assertSame([WorkflowCaseState::Active, null, null, 1], [$case->state, $case->completion_reason, $case->completed_at, $case->revision]);
        $this->assertSame(
            [['apply', 'done', 'apply', []], ['approval1', 'done', 'approve', []], ['approval2', 'waiting', null, [$this->seededUser('u0003')->id]]],
            $this->nodeStates($case),
        );
        $history = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame(
            [$this->caseNode($case, 'approval1')->id, $this->seededUser('u0002')->id, '確認しました', null],
            [$history->workflow_case_node_id, $history->user_id, $history->comment, $history->record_revision_id],
        );
        $this->assertNodeInvariant($case);
    }

    public function test_approving_the_last_node_completes_the_case(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case);

        $this->operate(ApproveCase::class, $this->seededUser('u0003'), $case);

        $case->refresh();
        $this->assertSame([WorkflowCaseState::Completed, WorkflowCompletionReason::Approved, 2], [$case->state, $case->completion_reason, $case->revision]);
        $this->assertNotNull($case->completed_at);
        $this->assertSame(['done', 'done', 'done'], array_column($this->nodeStates($case), 1));
        $this->assertSame([], app(WorkflowRecordHolds::class)->holdsOf([(int) $case->record_id]));
        $this->assertNodeInvariant($case);
    }

    /**
     * @return array<string, array{class-string<ApproveCaseEarly|RejectCase>, WorkflowCompletionReason, string}>
     */
    public static function completingOperations(): array
    {
        return [
            '承認終了' => [ApproveCaseEarly::class, WorkflowCompletionReason::ApprovedEarly, 'approve_early'],
            '否認' => [RejectCase::class, WorkflowCompletionReason::Rejected, 'reject'],
        ];
    }

    /**
     * 承認終了・否認は現在のノードを処理済みにして完了し、後のノードは到達しないまま残る。
     *
     * @param  class-string<ApproveCaseEarly|RejectCase>  $operation
     */
    #[DataProvider('completingOperations')]
    public function test_approving_early_or_rejecting_completes_the_case_leaving_later_nodes_unreached(string $operation, WorkflowCompletionReason $reason, string $action): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));

        $this->operate($operation, $this->seededUser('u0002'), $case, 'コメント');

        $case->refresh();
        $this->assertSame([WorkflowCaseState::Completed, $reason, 1], [$case->state, $case->completion_reason, $case->revision]);
        $this->assertNotNull($case->completed_at);
        $this->assertSame([['apply', 'done', 'apply', []], ['approval1', 'done', $action, []], ['approval2', 'unreached', null, []]], $this->nodeStates($case));
        $this->assertNodeInvariant($case);
    }

    /**
     * 取止めは再申請待ちの申請のノードでだけできる(承認待ちの案件は 422)。完了してもレコードは残り、持ち主が消える。
     */
    public function test_withdrawing_completes_a_case_waiting_for_reapplication(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->applyAs($u0001);

        $this->assertSame(self::NOT_PERMITTED, $this->validationErrors(fn () => $this->operate(WithdrawCase::class, $u0001, $case)));

        $this->operate(PullBackCase::class, $u0001, $case);
        $this->operate(WithdrawCase::class, $u0001, $case, '取り止めます');

        $case->refresh();
        $this->assertSame([WorkflowCaseState::Completed, WorkflowCompletionReason::Withdrawn, 2], [$case->state, $case->completion_reason, $case->revision]);
        $this->assertSame([['apply', 'done', 'withdraw', []], ['approval1', 'unreached', null, []], ['approval2', 'unreached', null, []]], $this->nodeStates($case));
        $this->assertNotNull($case->record_id);
        $this->assertSame([], app(WorkflowRecordHolds::class)->holdsOf([(int) $case->record_id]));
        $this->assertNodeInvariant($case);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidComments(): array
    {
        return [
            '4001 文字' => [str_repeat('あ', 4001), 'コメントは 4000 文字以内で入力してください'],
            'U+0000' => ["コ\0メント", 'コメントに使用できない文字が含まれています'],
        ];
    }

    /**
     * 処理の 6 つはどれもコメントの規則を掛け、誤りなら何も変わらない。
     */
    #[DataProvider('invalidComments')]
    public function test_rejects_an_invalid_comment(string $comment, string $message): void
    {
        $u0001 = $this->seededUser('u0001');
        $u0002 = $this->seededUser('u0002');
        $case = $this->applyAs($u0001);
        $counts = $this->workflowCounts();

        foreach ([ApproveCase::class, ApproveCaseEarly::class, RejectCase::class] as $operation) {
            $this->assertSame(['comment' => $message], $this->validationErrors(fn () => $this->operate($operation, $u0002, $case, $comment)));
        }

        $this->assertSame(['comment' => $message], $this->validationErrors(fn () => $this->operate(PullBackCase::class, $u0001, $case, $comment)));
        $this->assertSame([$counts, 0], [$this->workflowCounts(), $this->revisionOf($case)]);
        $this->operate(PullBackCase::class, $u0001, $case);
        $counts = $this->workflowCounts();
        $this->assertSame(['comment' => $message], $this->validationErrors(fn () => $this->operate(WithdrawCase::class, $u0001, $case, $comment)));
        $this->assertSame($counts, $this->workflowCounts());
        $this->assertSame(1, $this->revisionOf($case));
    }

    /**
     * 承認待ち → 引戻し → 再び承認待ち(状態が同じに戻る)の後に、最初に開いた画面の改訂番号で承認すると 422 case で何も変わらない。
     */
    public function test_an_approval_with_a_stale_revision_is_rejected(): void
    {
        [$u0002, $u0003] = [$this->seededUser('u0002'), $this->seededUser('u0003')];
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $u0002, $case);
        $opened = $this->revisionOf($case);
        $this->operate(PullBackCase::class, $u0002, $case);
        $this->operate(ApproveCase::class, $u0002, $case);
        $counts = $this->workflowCounts();
        $nodes = $this->nodeStates($case);

        $errors = $this->validationErrors(fn () => app(ApproveCase::class)->execute($this->contextOf($u0003), $case->id, $opened, $this->operatedNodeId($case->id, $u0003->id), null));

        $this->assertSame(['case' => '画面を表示した後に申請が変更されました。再表示してください'], $errors);
        $this->assertSame([$counts, $nodes, 3], [$this->workflowCounts(), $this->nodeStates($case), $this->revisionOf($case)]);
    }

    /**
     * 次の承認のノードの展開が 0 人(d に退職した人だけの指定)なら、承認は 422 case で何も変わらない。
     */
    public function test_approving_toward_a_node_without_targets_is_rejected(): void
    {
        $this->createFlow('to_retired', '退職者へ', approvals: [[$this->userCondition('u0002')], [$this->userCondition('u0014')]]);
        $case = $this->applyAs($this->seededUser('u0001'), 'to_retired');
        $counts = $this->workflowCounts();

        $this->assertSame(['case' => '承認2の処理担当者がいないため、この操作はできません'], $this->validationErrors(fn () => $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case)));
        $this->assertSame([$counts, 0], [$this->workflowCounts(), $this->revisionOf($case)]);

        $this->operate(ApproveCaseEarly::class, $this->seededUser('u0002'), $case);
        $this->assertSame(WorkflowCaseState::Completed, $case->refresh()->state);
    }

    /**
     * 閲覧できる人でも処理対象者でなければ 422 case(申請者の承認、管理の述語で閲覧できるシステム管理者 u0004、完了の後の承認)、
     * 閲覧できない人(u0006)・ゲストは 404。
     */
    public function test_a_person_who_is_not_the_current_target_cannot_process(): void
    {
        $u0001 = $this->seededUser('u0001');
        $case = $this->applyAs($u0001);

        foreach ([ApproveCase::class, ApproveCaseEarly::class, RejectCase::class] as $operation) {
            $this->assertSame(self::NOT_PERMITTED, $this->validationErrors(fn () => $this->operate($operation, $u0001, $case)));
            $byAdministrator = fn () => $this->operate($operation, $this->seededUser('u0004'), $case);
            $this->assertSame(self::NOT_PERMITTED, $this->validationErrors($byAdministrator));
            $this->assertThrows(fn () => $this->operate($operation, $this->seededUser('u0006'), $case), ModelNotFoundException::class);
            $this->assertThrows(fn () => $this->operate($operation, null, $case), ModelNotFoundException::class);
        }

        $this->operate(RejectCase::class, $this->seededUser('u0002'), $case);
        $this->assertSame(self::NOT_PERMITTED, $this->validationErrors(fn () => $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case)));
        $this->assertSame(1, WorkflowCase::query()->findOrFail($case->id)->revision);
    }
}
