<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\SendBackCase;
use App\Enums\Workflow\WorkflowAction;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 差戻し・引戻し・差戻しの引戻し(docs の 設計/ワークフロー/案件の操作/操作.md 3.7・3.8、同時実行と検査.md 7 節の「差戻し」「引戻し」「差戻しの引戻し」(1)〜(6))。
 * フロー = 申請 → 承認 1(u0002)→ 承認 2(u0003)→ 承認 3(u0010)。申請者 = u0001。どの操作の後も README 1.2 の不変の条件を確かめる。
 */
final class SendBackPullBackTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsWorkflowOperations;

    private const NOT_PERMITTED = ['case' => 'この申請は今この操作をできません'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow(approvals: [[$this->userCondition('u0002')], [$this->userCondition('u0003')], [$this->userCondition('u0010')]]);
    }

    public function test_sending_back_to_the_apply_node(): void
    {
        $case = $this->applyAs($this->user(1));

        $this->sendBack($this->user(2), $case, 'apply', '直してください');

        $this->assertNodes($case, [['apply', 'reapply', null, [$this->user(1)->id]], ['approval1', 'unreached', null, []], ['approval2', 'unreached', null, []], ['approval3', 'unreached', null, []]]);
        $history = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame([WorkflowAction::SendBack, $this->caseNode($case, 'approval1')->id, $this->caseNode($case, 'apply')->id, '直してください'], [$history->action, $history->workflow_case_node_id, $history->send_back_workflow_case_node_id, $history->comment]);
        $this->assertSame(1, $this->revisionOf($case));
    }

    /**
     * 前の承認のノードへ戻すと、処理対象者は以前そのノードを処理した人 1 人、差戻し先より前は変わらない。間のノードは到達していないに戻る。
     */
    public function test_sending_back_to_a_previous_approval_unreaches_the_nodes_between(): void
    {
        $case = $this->appliedAndApproved(2);

        $this->sendBack($this->user(10), $case, 'approval1');

        $this->assertNodes($case, [['apply', 'done', 'apply', []], ['approval1', 'waiting', null, [$this->user(2)->id]], ['approval2', 'unreached', null, []], ['approval3', 'unreached', null, []]]);
    }

    /**
     * 差戻し元に承認で再び来ると、到達し直して展開する(前に処理済みでも)。
     */
    public function test_coming_back_to_the_origin_reaches_it_again(): void
    {
        $case = $this->appliedAndApproved(1);
        $this->sendBack($this->user(3), $case, 'approval1');

        $this->operate(ApproveCase::class, $this->user(2), $case);

        $this->assertNodes($case, [['apply', 'done', 'apply', []], ['approval1', 'done', 'approve', []], ['approval2', 'waiting', null, [$this->user(3)->id]], ['approval3', 'unreached', null, []]]);
    }

    /**
     * 候補に無い差戻し先(現在のノード・現在より後・別の案件のノード・無い id)は 422 send_back_node_id で何も変わらない。
     */
    public function test_a_send_back_node_outside_the_candidates_is_rejected(): void
    {
        $case = $this->appliedAndApproved(1);
        $other = $this->applyAs($this->user(1));
        $counts = $this->workflowCounts();
        $send = fn (int $nodeId) => $this->validationErrors(fn () => app(SendBackCase::class)->execute($this->contextOf($this->user(3)), $case->id, $this->revisionOf($case), $this->operatedNodeId($case->id, $this->user(3)->id), $nodeId, null));

        foreach ([$this->caseNode($case, 'approval2')->id, $this->caseNode($case, 'approval3')->id, $this->caseNode($other, 'apply')->id, 999_999_999] as $nodeId) {
            $this->assertSame(['send_back_node_id' => '差戻し先を、差戻しできるステップから選択してください'], $send($nodeId));
        }

        $this->assertSame([$counts, 1], [$this->workflowCounts(), $this->revisionOf($case)]);
    }

    /**
     * 差戻し先を以前処理した人は申請基準日で確かめる(ルートの深さ/案件の操作/処理対象者.md 4.5。採った方針 7)。u0014 が退職の前(2025-09-01。申請基準日も同じ)に
     * 申請した案件を、退職の後に申請へ戻すと、u0014 が再申請待ちの処理対象者になる(段 2 は今日で確かめて 422 case だった)。
     */
    public function test_sending_back_reaches_a_previous_actor_effective_on_the_base_date_even_after_retiring(): void
    {
        $retired = $this->seededUser('u0014');
        $case = $this->applyAs($retired, date: '2025-09-01');

        $this->sendBack($this->user(2), $case, 'apply');

        $this->assertNodes($case, [['apply', 'reapply', null, [$retired->id]], ['approval1', 'unreached', null, []], ['approval2', 'unreached', null, []], ['approval3', 'unreached', null, []]]);
        $this->assertSame(1, $this->revisionOf($case));
    }

    /**
     * 引戻しは申請・再申請・承認の直後に通り、自分のノードへ戻る(処理対象者 = 自分 1 人、現在のノードは到達していない)。差戻しの直後は差戻しの引戻しの各テスト。
     */
    public function test_pulling_back_after_apply_reapply_and_approve(): void
    {
        $u0001 = $this->user(1);
        $case = $this->applyAs($u0001);

        $this->operate(PullBackCase::class, $u0001, $case);
        $this->assertNodes($case, [['apply', 'reapply', null, [$u0001->id]], ['approval1', 'unreached', null, []], ['approval2', 'unreached', null, []], ['approval3', 'unreached', null, []]]);
        $this->reapply($u0001, $case);
        $this->operate(PullBackCase::class, $u0001, $case);
        $this->assertNodes($case, [['apply', 'reapply', null, [$u0001->id]], ['approval1', 'unreached', null, []], ['approval2', 'unreached', null, []], ['approval3', 'unreached', null, []]]);
        $this->reapply($u0001, $case);
        $this->operate(ApproveCase::class, $this->user(2), $case);
        $this->operate(PullBackCase::class, $this->user(2), $case, '戻します');

        $this->assertNodes($case, [['apply', 'done', 'reapply', []], ['approval1', 'waiting', null, [$this->user(2)->id]], ['approval2', 'unreached', null, []], ['approval3', 'unreached', null, []]]);
        $pullBack = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderByDesc('id')->firstOrFail();
        $this->assertSame([WorkflowAction::PullBack, $this->caseNode($case, 'approval1')->id, '戻します'], [$pullBack->action, $pullBack->workflow_case_node_id, $pullBack->comment]);
    }

    /**
     * 引戻しの後・次の人が処理した後・完了の後・直前でない以前の処理者は 422 case で何も変わらない。
     */
    public function test_pulling_back_is_rejected_when_the_requester_is_not_the_last_actor(): void
    {
        $case = $this->applyAs($this->user(1));
        $this->operate(ApproveCase::class, $this->user(2), $case);
        $this->operate(PullBackCase::class, $this->user(2), $case);
        $this->assertRejected($this->user(2), $case);
        $this->operate(ApproveCase::class, $this->user(2), $case);
        $this->operate(ApproveCase::class, $this->user(3), $case);

        $this->assertRejected($this->user(1), $case);
        $this->assertRejected($this->user(2), $case);

        $this->operate(ApproveCase::class, $this->user(10), $case);

        $this->assertRejected($this->user(10), $case);
    }

    /**
     * (1) 承認 2 から申請へ差戻して引戻す = 申請のノードと承認 1 が差戻しの前の行で処理済みに戻り、承認 2 が承認待ち(処理対象者 = 引戻した人)。
     */
    public function test_undoing_a_send_back_to_the_apply_node(): void
    {
        $case = $this->appliedAndApproved(1);
        [$apply, $approve] = $this->historyIds($case);
        $this->sendBack($this->user(3), $case, 'apply');

        $this->operate(PullBackCase::class, $this->user(3), $case);

        $this->assertNodes($case, [['apply', 'done', 'apply', []], ['approval1', 'done', 'approve', []], ['approval2', 'waiting', null, [$this->user(3)->id]], ['approval3', 'unreached', null, []]]);
        $this->assertSame([$apply, $approve], [$this->caseNode($case, 'apply')->workflow_case_history_id, $this->caseNode($case, 'approval1')->workflow_case_history_id]);
        $this->assertSame($this->caseNode($case, 'approval2')->id, WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderByDesc('id')->firstOrFail()->workflow_case_node_id);
    }

    /**
     * (2) 承認 3 から承認 1 へ差戻して引戻す = 承認 1・2 が戻り、申請のノードは変わらない。(4) 復元の後に、承認 1 と間のノードが差戻しの候補に出る。
     * (5) 履歴は差戻しと引戻しの 2 行だけ増え、前の行は変わらない。
     */
    public function test_undoing_a_send_back_to_a_previous_approval_restores_the_nodes_between(): void
    {
        $case = $this->appliedAndApproved(2);
        $before = $this->historyRows($case);
        $this->sendBack($this->user(10), $case, 'approval1');

        $this->operate(PullBackCase::class, $this->user(10), $case);

        $this->assertNodes($case, [['apply', 'done', 'apply', []], ['approval1', 'done', 'approve', []], ['approval2', 'done', 'approve', []], ['approval3', 'waiting', null, [$this->user(10)->id]]]);
        $this->assertSame([$before[1][0], $before[2][0]], [$this->caseNode($case, 'approval1')->workflow_case_history_id, $this->caseNode($case, 'approval2')->workflow_case_history_id]);
        $after = $this->historyRows($case);
        $this->assertSame($before, array_slice($after, 0, count($before)));
        $this->assertSame(['send_back', 'pull_back'], array_column(array_slice($after, count($before)), 1));

        $this->sendBack($this->user(10), $case, 'approval2');
        $this->assertNodes($case, [['apply', 'done', 'apply', []], ['approval1', 'done', 'approve', []], ['approval2', 'waiting', null, [$this->user(3)->id]], ['approval3', 'unreached', null, []]]);
    }

    /**
     * (3) 再申請の後や、同じノードが 2 回処理された後(差戻し → 再申請 → 承認 → 差戻し → 引戻し)は、id が最大の前の行(再申請・2 回目の承認)を指す。
     */
    public function test_undoing_a_send_back_points_to_the_latest_previous_rows(): void
    {
        $case = $this->appliedAndApproved(1);
        $this->sendBack($this->user(3), $case, 'apply');
        $this->reapply($this->user(1), $case);
        $this->operate(ApproveCase::class, $this->user(2), $case);
        [$reapply, $secondApprove] = array_slice($this->historyIds($case), -2);
        $this->sendBack($this->user(3), $case, 'apply');

        $this->operate(PullBackCase::class, $this->user(3), $case);

        $this->assertNodes($case, [['apply', 'done', 'reapply', []], ['approval1', 'done', 'approve', []], ['approval2', 'waiting', null, [$this->user(3)->id]], ['approval3', 'unreached', null, []]]);
        $this->assertSame([$reapply, $secondApprove], [$this->caseNode($case, 'apply')->workflow_case_history_id, $this->caseNode($case, 'approval1')->workflow_case_history_id]);
    }

    /**
     * 初期データのユーザー u00NN。
     */
    private function user(int $number): User
    {
        return $this->seededUser(sprintf('u%04d', $number));
    }

    /**
     * u0001 が申請し、承認 1 から順に $approvals 個のノードを承認した案件(承認 1 = u0002、承認 2 = u0003)。
     */
    private function appliedAndApproved(int $approvals): WorkflowCase
    {
        $case = $this->applyAs($this->user(1));

        foreach (array_slice([2, 3, 10], 0, $approvals) as $number) {
            $this->operate(ApproveCase::class, $this->user($number), $case);
        }

        return $case;
    }

    /**
     * ノードの今の形と、不変の条件(6)。
     *
     * @param  list<array{string, string, string|null, list<int>}>  $expected
     */
    private function assertNodes(WorkflowCase $case, array $expected): void
    {
        $this->assertSame($expected, $this->nodeStates($case));
        $this->assertNodeInvariant($case);
    }

    private function assertRejected(User $user, WorkflowCase $case): void
    {
        $counts = $this->workflowCounts();
        $revision = $this->revisionOf($case);

        $this->assertSame(self::NOT_PERMITTED, $this->validationErrors(fn () => $this->operate(PullBackCase::class, $user, $case)));
        $this->assertSame([$counts, $revision], [$this->workflowCounts(), $this->revisionOf($case)]);
    }

    /**
     * @return list<int>
     */
    private function historyIds(WorkflowCase $case): array
    {
        return array_values(array_map('intval', WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderBy('id')->pluck('id')->all()));
    }

    /**
     * 履歴の行(id・種別・ノード・処理者・差戻し先・コメント・作った日時)。
     *
     * @return list<array{int, string, int, int, int|null, string|null, string|null}>
     */
    private function historyRows(WorkflowCase $case): array
    {
        return array_values(WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderBy('id')->get()->map(fn (WorkflowCaseHistory $history) => [
            $history->id, $history->action->value, $history->workflow_case_node_id, $history->user_id, $history->send_back_workflow_case_node_id, $history->comment, $history->created_at?->toIso8601String(),
        ])->all());
    }
}
