<?php

namespace Tests\Feature\Actions\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 差戻しの引戻し・展開した承認の引戻しを分岐・合議・配置の親のあるルートで(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.5、同時実行と検査.md 14 節の
 * 状況「差戻しの引戻しの直後」。区切り 3-3 のレビューの #7)。期待 = 引戻した行の操作の直前の状態(各ノードの状態・指している履歴の行・処理対象者・分岐開始の
 * 出る辺の taken、配置の親の展開の行)に戻る。各操作の後に不変の条件 5 つを確かめる({@see OperatesOnRoutes})。申請者 u0001。
 */
final class CaseSendBackPullBackOnRoutesTest extends TransactionalTestCase
{
    use OperatesOnRoutes;

    /**
     * (a) 合議の子から分岐の前(課長)へ差戻した後に、差戻した人が引戻すと、課長(処理済み・同じ行)、分岐(通過・taken)、省いた部長、合議の親と子(差戻しの前に
     * 票を入れた子 2 は処理済みで同じ行)が差戻しの直前に戻る。
     */
    public function test_undoing_a_send_back_before_a_branch_restores_the_branch_and_the_council(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005', 'u0009'], 'owner' => ['u0010']]);
        $vote = $this->approve($case, 'council.2', 'u0005');
        $before = [$this->shape($case), $this->historyRefs($case), $this->takenOf($case)];
        $this->sendBackOn($case, 'council.1', 'u0004', 'section_chief');

        $this->pullBackOn($case, 'council.1', 'u0004');

        $this->assertSame($before, [$this->shape($case), $this->historyRefs($case), $this->takenOf($case)]);
        $this->assertSame([false, true], $before[2]);
        $this->assertSame(['done', $vote->historyId], [$this->shape($case)['council.2'], $this->historyRefs($case)['council.2']]);
    }

    /**
     * (b) 縦配置の親へ差戻した差戻しの行の node_changes は、差戻しの前からある行だけを持つ(展開の行を足していない)。引戻すと親(通過)と子(処理済み・省略、
     * 指していた行)と後のノードが差戻しの直前に戻る。
     */
    public function test_undoing_a_send_back_to_a_parallel_parent_restores_the_children(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Parallel));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0004', 'u0005', 'u0009']]);
        $this->approve($case, 'family.1', 'u0004');
        $this->approve($case, 'family.2', 'u0005');
        $before = [$this->shape($case), $this->historyRefs($case)];
        $existing = array_values($this->labelIds($case));

        $sendBack = $this->sendBackOn($case, 'final', 'u0010', 'family');

        $changed = array_map(fn (array $change) => (int) $change['node'], WorkflowCaseHistory::query()->findOrFail($sendBack->historyId)->node_changes ?? []);
        $this->assertSame([], array_values(array_diff($changed, $existing)));
        $this->assertSame($existing, array_values($this->labelIds($case)));

        $this->pullBackOn($case, 'final', 'u0010');

        $this->assertSame($before, [$this->shape($case), $this->historyRefs($case)]);
    }

    /**
     * (c) 親を最初に展開した承認(R1 の部長)を引戻すと、足した展開の行は到達していないで残り、親(縦配置)は到達していないに戻る。承認し直すと同じ行を使って
     * 展開し、行が増えない(10.5 の段 2 との対応・案件の表.md 3.7)。
     */
    public function test_pulling_back_the_approval_that_expanded_a_parent_keeps_the_added_children_for_the_next_expansion(): void
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 500]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);
        $this->approve($case, 'manager', 'u0003');
        $children = $this->childIdsOf($case);

        $this->pullBackOn($case, 'manager', 'u0003');

        $this->assertSame(['council' => 'unreached', 'council.1' => 'unreached', 'council.2' => 'unreached'], $this->pick($case, 'council', 'council.1', 'council.2'));
        $this->assertSame($children, $this->childIdsOf($case));

        $this->approve($case, 'manager', 'u0003');

        $this->assertSame(['council' => 'running', 'council.1' => 'waiting[u0004]', 'council.2' => 'waiting[u0005]'], $this->pick($case, 'council', 'council.1', 'council.2'));
        $this->assertSame($children, $this->childIdsOf($case));
    }

    /**
     * @return array<string, int|null> 名札 → 指している履歴の行
     */
    private function historyRefs(WorkflowCase $case): array
    {
        return array_map(fn (int $id) => WorkflowCaseNode::query()->findOrFail($id)->workflow_case_history_id, $this->labelIds($case));
    }

    /**
     * @return list<bool|null> 分岐「予算」から出る辺の taken(並びの順)
     */
    private function takenOf(WorkflowCase $case): array
    {
        return array_values(WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->where('from_workflow_case_node_id', $this->labelIds($case)['budget'])->orderBy('sort_key')->get()->map(fn (WorkflowCaseEdge $edge) => $edge->taken)->all());
    }

    /**
     * @return list<int> 合議の展開の行の id(番号の順)
     */
    private function childIdsOf(WorkflowCase $case): array
    {
        return array_values(array_map('intval', WorkflowCaseNode::query()->where('parent_workflow_case_node_id', $this->labelIds($case)['council'])->orderBy('sequence')->pluck('id')->all()));
    }
}
