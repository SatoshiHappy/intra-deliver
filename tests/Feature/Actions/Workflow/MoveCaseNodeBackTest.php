<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\MoveCaseNode;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\PullBackableNodes;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの移動の戻る(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.5 節・15.2 節、同時実行と検査.md の 13 節・14.2 節・14.3 節の
 * 「差戻し × 管理者のノード移動」(1)〜(4)): 候補は祖先の候補の全部で、差戻しできる先の制限を受けない。結果は差戻しと同じ(戻り先と子孫を戻し、戻り先に
 * 以前の処理者で到達)で、履歴 = 戻る(差戻し先の列 = 戻り先)。移動の種類と戻り先の誤りの 422 と、移動の種類より表を先に見ること。管理者 = u0004。
 * 一本道 = 申請 → a1(u0002。差戻しできる先 = 申請)→ a2(u0003。差戻しできる先なし)→ a3(u0010。申請・a1・a2)。
 */
final class MoveCaseNodeBackTest extends TransactionalTestCase
{
    use OperatesAsCaseManager;
    use ReadsCaseNotices;

    private const BACK_NODE = ['send_back_node_id' => '戻り先を、戻せるステップから選択してください'];

    private bool $hasLinearRoute = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * 差戻しできる先の無い a2 からも a1 へ戻れる(差戻しは 422)。a1 = 以前の処理者 u0002 の承認待ち、a2 = 到達していない。履歴 = 戻る(処理者 = 管理者、
     * 実行者 = null、差戻し先の列 = a1)、改訂番号 +1。u0003 の未処理から消え u0002 の未処理と管理者の処理済に出て、u0002 に処理依頼が届く(管理者には届かない)。
     */
    public function test_moving_back_reaches_the_target_without_the_send_back_restriction(): void
    {
        $case = $this->linearCase();
        $this->approve($case, 'a1', 'u0002');
        $sendBack = $this->attemptOf(fn () => $this->sendBackOn($case, 'a2', 'u0003', 'a1'));
        $revision = $this->revisionOf($case);
        $lastId = $this->lastNoticeId();

        $this->moveOn($case, 'a2', 'back', 'a1', comment: '確認のため');

        $this->assertSame(['send_back_node_id' => 'このステップでは差戻しできません'], $sendBack);
        $nodes = $this->pick($case, 'a1', 'a2', 'a3');
        $this->assertSame(['a1' => 'waiting[u0002]', 'a2' => 'unreached', 'a3' => 'unreached'], $nodes);
        $row = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->latest('id')->firstOrFail();
        $actual = [
            $row->action->value, $row->user_id, $row->executor_user_id,
            $row->send_back_workflow_case_node_id, $row->comment,
        ];
        $expected = ['move_back', $this->seededUser(self::MANAGER)->id, null, $this->labelIds($case)['a1'], '確認のため'];
        $this->assertSame([$expected, $revision + 1], [$actual, $this->revisionOf($case)]);
        $this->assertSame(
            ['u0002' => ['pending', 'processed'], 'u0003' => [], self::MANAGER => ['processed']],
            $this->appearances($case, 'u0002', 'u0003', self::MANAGER),
        );
        $request = ['step_name' => 'a1', 'step_kind' => 'approve'];
        $notice = $this->caseNotice(WorkflowNoticeType::ProcessRequest, 'u0002', self::MANAGER, $case, $request);
        $this->assertSame([$notice], $this->noticesSince($lastId));
    }

    /**
     * 候補(処理済みか通過の祖先)に無い戻り先 = 自分・到達していない後のノード・別の案件のノード・無し → 422 send_back_node_id。再申請待ちの案件(申請の
     * ノードだけが現在のノード)は候補が無い。どれも何も変わらず、通知も無い。
     */
    public function test_a_target_outside_the_ancestor_candidates_is_rejected(): void
    {
        $case = $this->linearCase();
        $other = $this->linearCase();
        $otherApply = $this->labelIds($other)['apply'];
        $ids = $this->labelIds($case);
        $before = $this->snapshot($case);
        $lastId = $this->lastNoticeId();

        $errors = array_map(
            fn (?int $toNodeId) => $this->attemptOf(fn () => $this->moveTo($case, 'a1', $toNodeId)),
            [$ids['a1'], $ids['a2'], $otherApply, null],
        );
        $afterErrors = [$this->snapshot($case), $this->noticesSince($lastId)];
        $this->sendBackOn($case, 'a1', 'u0002', 'apply');
        $waiting = $this->snapshot($case);
        $reapply = $this->attemptOf(fn () => $this->moveOn($case, 'apply', 'back', 'apply'));

        $this->assertSame(array_fill(0, 5, self::BACK_NODE), [...$errors, $reapply]);
        $this->assertSame([$before, []], $afterErrors);
        $this->assertSame($waiting, $this->snapshot($case));
    }

    /**
     * 戻り先を以前処理した人が申請基準日に有効でない(処理者を退職の u0014 に書き換えた)→ 422 case(差戻しと同じ文言)で何も変わらない。
     */
    public function test_the_previous_processor_must_be_effective_on_the_base_date(): void
    {
        $case = $this->linearCase();
        $this->approve($case, 'a1', 'u0002');
        $historyId = WorkflowCaseNode::query()->findOrFail($this->labelIds($case)['a1'])->workflow_case_history_id;
        WorkflowCaseHistory::query()->whereKey($historyId)->update(['user_id' => $this->seededUser('u0014')->id]);
        $before = $this->snapshot($case);

        $error = $this->attemptOf(fn () => $this->moveOn($case, 'a2', 'back', 'a1'));

        $this->assertSame(['case' => 'a1の処理対象者がいないため、この操作はできません'], $error);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * R1 の合議の子から課長へ戻る: 合議の親と子・責任者(後のノード)が到達していないに戻り、課長が以前の処理者 u0002 の承認待ち(14.3 節の (2))。
     */
    public function test_moving_back_from_a_council_child_to_the_section_chief(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $designations = ['council' => ['u0005', 'u0009', 'u0011'], 'owner' => ['u0007']];
        $this->approve($case, 'section_chief', 'u0002', $designations);
        $this->approve($case, 'council.1', 'u0005');

        $this->moveOn($case, 'council.2', 'back', 'section_chief');

        $this->assertSame(
            [
                'section_chief' => 'waiting[u0002]', 'council' => 'unreached', 'council.1' => 'unreached',
                'council.2' => 'unreached', 'council.3' => 'unreached', 'owner' => 'unreached',
            ],
            $this->pick($case, 'section_chief', 'council', 'council.1', 'council.2', 'council.3', 'owner'),
        );
    }

    /**
     * 戻るの後に、それより前の差戻しの処理者(a3 の u0010)は、その差戻しを引戻せない(戻るが差戻しの影響の範囲を変えた = 14.3 節の (3))。戻るの行は
     * 管理者も引戻せない。
     */
    public function test_a_send_back_before_moving_back_cannot_be_pulled_back(): void
    {
        $case = $this->linearCase();
        $this->approve($case, 'a1', 'u0002');
        $this->approve($case, 'a2', 'u0003');
        $this->sendBackOn($case, 'a3', 'u0010', 'a1');
        $before = $this->pullBackableLabels($case, 'u0010');

        $this->moveOn($case, 'a1', 'back', 'apply');

        $this->assertSame([['a3'], []], [$before, $this->pullBackableLabels($case, 'u0010')]);
        $errors = [
            $this->attemptOf(fn () => $this->pullBackOn($case, 'a3', 'u0010')),
            $this->attemptOf(fn () => $this->pullBackOn($case, 'a1', self::MANAGER)),
        ];
        $this->assertSame(array_fill(0, 2, ['case' => 'この申請は今この操作をできません']), $errors);
    }

    /**
     * R2 の枝 A の中で a2 から a1 へ戻ると、枝 B の b1(u0004 の承認待ち)と同期は変わらない(14.3 節の (4))。
     */
    public function test_moving_back_in_one_sync_branch_leaves_the_other_branch(): void
    {
        $this->addVersion($this->r2());
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'a1', 'u0002');
        $branchB = $this->pick($case, 'sync', 'b1', 'sync_end');

        $this->moveOn($case, 'a2', 'back', 'a1');

        $this->assertSame(['sync' => 'passed', 'b1' => 'waiting[u0004]', 'sync_end' => 'unreached'], $branchB);
        $this->assertSame($branchB, $this->pick($case, 'sync', 'b1', 'sync_end'));
        $this->assertSame(['a1' => 'waiting[u0002]', 'a2' => 'unreached'], $this->pick($case, 'a1', 'a2'));
    }

    /**
     * 保留中のノード(a2 を u0003 が保留)からも戻れ、保留は消える(到達していないに戻る = 15.2 節の保留の行き先)。
     */
    public function test_a_held_node_can_be_moved_back(): void
    {
        $case = $this->linearCase();
        $this->approve($case, 'a1', 'u0002');
        $this->holdOn($case, 'a2', 'u0003');

        $this->moveOn($case, 'a2', 'back', 'a1');

        $this->assertSame(['a1' => 'waiting[u0002]', 'a2' => 'unreached'], $this->pick($case, 'a1', 'a2'));
    }

    /**
     * 移動の種類が無い・外の値 → 422 direction で何も変わらない。現在のノードでないノード(処理済みの a1)は、移動の種類が外の値でも表が先で 422 case
     * (判定の順 = 15.1 節の 5 → 6)。
     */
    public function test_the_direction_is_checked_after_the_table(): void
    {
        $case = $this->linearCase();
        $this->approve($case, 'a1', 'u0002');
        $before = $this->snapshot($case);

        $errors = [
            $this->attemptOf(fn () => $this->moveOn($case, 'a2', '')),
            $this->attemptOf(fn () => $this->moveOn($case, 'a2', 'sideways')),
            $this->attemptOf(fn () => $this->moveOn($case, 'a1', 'sideways')),
        ];

        $direction = ['direction' => '移動の種類を選択してください'];
        $this->assertSame([$direction, $direction, ['case' => 'この申請は今この操作をできません']], $errors);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * 一本道の案件(a1 が u0002 の承認待ち)。
     */
    private function linearCase(): WorkflowCase
    {
        if (! $this->hasLinearRoute) {
            $nodes = [
                $this->applyNode(),
                $this->approveNode('a1', 'u0002', ['apply']),
                $this->approveNode('a2', 'u0003'),
                $this->approveNode('a3', 'u0010', ['apply', 'a1', 'a2']),
            ];
            $this->addVersion([$nodes, $this->chain(['apply', 'a1'], ['a1', 'a2'], ['a2', 'a3'])]);
            $this->hasLinearRoute = true;
        }

        return $this->applyOnRoute([])[0];
    }

    /**
     * 戻り先をノードの id で渡す戻る(名札の無い値・別の案件のノードを渡す)。
     */
    private function moveTo(WorkflowCase $case, string $label, ?int $toNodeId): void
    {
        $context = $this->contextOf($this->seededUser(self::MANAGER));
        $nodeId = $this->labelIds($case)[$label];
        $revision = $this->revisionOf($case);
        app(MoveCaseNode::class)->execute($context, $case->id, $revision, $nodeId, 'back', $toNodeId, null);
    }

    /**
     * @return list<string> その人が引戻せるノードの名札
     */
    private function pullBackableLabels(WorkflowCase $case, string $userCode): array
    {
        $labels = array_flip($this->labelIds($case));
        $nodes = app(PullBackableNodes::class)->of([$case->id], $this->seededUser($userCode)->id)[$case->id];

        return array_map(fn (WorkflowCaseNode $node) => $labels[$node->id], $nodes);
    }

    /**
     * @return array{array<string, string>, array<string, int>, int}
     */
    private function snapshot(WorkflowCase $case): array
    {
        return [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
    }
}
