<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseVisibility;
use App\Queries\Workflow\CurrentNode;
use App\Queries\Workflow\CurrentNodes;
use App\Queries\Workflow\WorkflowHomeLabels;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 読み取りは保留のノードを現在のノードとして読む(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.3 節。現在のノードの状態の一覧
 * = WorkflowNodeState::current() の 1 か所): 現在のノードの集合の口が保留のノードを返し、未処理の述語は保留のノードの処理対象者に当たる。
 * 経路の点は現在のノードの点で、語は「保留中」(設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節)。
 */
final class CurrentNodesHoldTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;

    public function test_the_current_nodes_include_a_held_node_with_its_targets(): void
    {
        [$case, $held, $target] = $this->caseWithAHeldNode();

        $nodes = app(CurrentNodes::class)->of([$case->id])[$case->id];

        $this->assertSame(
            [[$held->id, WorkflowNodeState::Hold, [$target]]],
            array_map(fn (CurrentNode $node) => [$node->id, $node->state, $node->targetUserIds], $nodes),
        );
    }

    public function test_the_pending_predicate_reaches_the_target_of_a_held_node(): void
    {
        [$case, , $target] = $this->caseWithAHeldNode();

        $query = WorkflowCase::query()->whereKey($case->id);
        $date = CarbonImmutable::parse('2026-10-13');
        $pending = app(CaseVisibility::class)->pending($query, new Requester($target, null), $date);

        $this->assertSame([$case->id], $pending->pluck('id')->all());
    }

    public function test_a_held_step_is_drawn_as_a_current_step_with_its_own_word(): void
    {
        $this->assertSame(['current', '保留中'], WorkflowHomeLabels::step(WorkflowNodeState::Hold));
    }

    /**
     * 申請・承認 2 つの一本道で、承認 1 が保留(処理対象者 u0002)の案件。
     *
     * @return array{WorkflowCase, WorkflowCaseNode, int}
     */
    private function caseWithAHeldNode(): array
    {
        [$applicant, $approver] = $this->seededUsers('u0001', 'u0002');
        $case = WorkflowCase::factory()->active()->createOne(['user_id' => $applicant->id]);
        [$apply, $held] = $this->caseNodes($case, 3);
        $this->process($apply, $applicant, WorkflowAction::Apply);
        $this->reach($held, $approver);
        $held->update(['state' => WorkflowNodeState::Hold]);

        return [$case, $held, $approver->id];
    }
}
