<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseOperationDecider;
use App\Queries\Workflow\CaseTargetTerm;
use App\Queries\Workflow\CaseTargetTerms;
use App\Queries\Workflow\CaseVisibility;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\DelegatedCaseTargetTerm;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 処理対象者に当たる条件の 1 か所(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 3 節、7 節の「タグに項を 1 つ足すと、未処理の述語・操作の役割・
 * 候補の入口の判定がそろって変わる」= 代理を足すときの拡張点のテスト)。候補の入口は、指定するノードの処理対象者の判定に
 * {@see CaseTargetTerms::targetNodeIds()} を使うので、ここではその結果で確かめる。
 */
final class CaseTargetTermsTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use RunsWorkflowOperations;

    public function test_the_3a_term_matches_only_the_target_user_itself(): void
    {
        [$case, $node] = $this->caseWaitingFor('u0002');
        [$u0002, $u0003] = $this->seededUsers('u0002', 'u0003');

        $this->assertSame([true, true, [$node->id]], $this->judgements($case, $node, $u0002));
        $this->assertSame([false, false, []], $this->judgements($case, $node, $u0003));
    }

    public function test_adding_a_term_to_the_tag_changes_the_pending_predicate_the_role_and_the_candidate_gate_together(): void
    {
        [$case, $node] = $this->caseWaitingFor('u0002');
        [$u0002, $u0003, $u0004] = $this->seededUsers('u0002', 'u0003', 'u0004');
        $this->app->instance('test.case-target-term', new DelegatedCaseTargetTerm($u0002->id, $u0003->id));
        $this->app->tag(['test.case-target-term'], CaseTargetTerm::TAG);

        $this->assertSame([true, true, [$node->id]], $this->judgements($case, $node, $u0003));
        $this->assertSame([true, true, [$node->id]], $this->judgements($case, $node, $u0002));
        $this->assertSame([false, false, []], $this->judgements($case, $node, $u0004));
    }

    public function test_a_target_row_of_a_node_that_is_not_current_does_not_make_the_case_pending(): void
    {
        [$case, $node] = $this->caseWaitingFor('u0002');
        $node->update(['state' => WorkflowNodeState::Unreached]);
        $u0002 = $this->seededUser('u0002');

        $this->assertFalse(app(CaseVisibility::class)->pending(WorkflowCase::query()->whereKey($case->id), $this->requesterOf($u0002), $this->operationDay())->exists());
        $this->assertSame([$node->id], app(CaseTargetTerms::class)->targetNodeIds([$node->id], $u0002->id, $this->operationDay()));
    }

    public function test_without_any_term_nobody_is_a_target(): void
    {
        [, $node] = $this->caseWaitingFor('u0002');

        $this->assertSame([], (new CaseTargetTerms([]))->targetNodeIds([$node->id], $this->seededUser('u0002')->id, $this->operationDay()));
        $this->assertSame([], app(CaseTargetTerms::class)->targetNodeIds([], $this->seededUser('u0002')->id, $this->operationDay()));
    }

    /**
     * 本物の業務処理で申請した案件(承認 1 = 処理対象者が $targetCode の承認待ち)。
     *
     * @return array{WorkflowCase, WorkflowCaseNode}
     */
    private function caseWaitingFor(string $targetCode): array
    {
        $this->createFlow(approvals: [[$this->userCondition($targetCode)]]);
        $case = $this->applyAs($this->seededUser('u0001'));

        return [$case, $this->caseNode($case, 'approval1')];
    }

    /**
     * [未処理の述語に出る, 操作の役割「ノードの処理対象者」(本番の判定 = 状況の現在のノードについて), 候補の入口の判定(処理対象者に当たるノード)]。
     *
     * @return array{bool, bool, list<int>}
     */
    private function judgements(WorkflowCase $case, WorkflowCaseNode $node, User $user): array
    {
        $date = $this->operationDay();
        $requester = $this->requesterOf($user);
        $case = WorkflowCase::query()->findOrFail($case->id);
        $situation = app(CaseOperationDecider::class)->situationsOf($requester, $date, [$case])[$case->id];
        $current = $situation->currentNode($node->id) ?? $this->fail('ノードが現在のノードでない');

        return [
            app(CaseVisibility::class)->pending(WorkflowCase::query()->whereKey($case->id), $requester, $date)->exists(),
            $situation->isTargetOf($current),
            app(CaseTargetTerms::class)->targetNodeIds([$node->id], $user->id, $date),
        ];
    }

    private function requesterOf(User $user): Requester
    {
        return new Requester($user->id, null);
    }
}
