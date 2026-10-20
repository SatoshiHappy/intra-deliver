<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\PullBackCase;
use App\Enums\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowFlowVersion;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 段 2 の操作が書く、段 3a の履歴と案件の列(docs の 設計/ワークフロー/ルートの深さ/データモデル/案件の表.md 3.1・3.7): 申請は申請基準日(判定の基準日)に有効な版を写し、
 * 版と申請基準日を案件に書く。引戻し以外の行は、その操作が変えた(行のノード以外の)ノードの操作の前の状態を node_changes に持ち(ノードの id の昇順。
 * 処理対象者は id の昇順)、引戻しの行は node_changes を持たず引戻した行を指す。フロー = 申請 → 承認 1(u0002)→ 承認 2(u0003)。申請者 = u0001。
 */
final class CaseHistoryNodeChangesTest extends TransactionalTestCase
{
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
    }

    public function test_the_application_copies_the_version_of_the_base_date_and_records_the_first_approval_before_reaching_it(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));

        $version = WorkflowFlowVersion::query()->where('workflow_flow_id', $case->workflow_flow_id)->sole();
        $this->assertSame([$version->id, $this->operationDay()->toDateString()], [$case->workflow_flow_version_id, $case->base_date?->toDateString()]);
        $this->assertSame([[$this->caseNode($case, 'approval1')->id, 'unreached', null, []]], $this->changesOf($this->latestHistory($case)));
    }

    public function test_an_approval_records_the_next_node_and_the_last_approval_records_nothing(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));

        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case);
        $this->assertSame([[$this->caseNode($case, 'approval2')->id, 'unreached', null, []]], $this->changesOf($this->latestHistory($case)));

        $this->operate(ApproveCase::class, $this->seededUser('u0003'), $case);
        $this->assertSame([], $this->changesOf($this->latestHistory($case)));
    }

    public function test_a_send_back_records_the_nodes_between_and_the_destination(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case);
        $apply = $this->caseNode($case, 'apply');
        $approval1 = $this->caseNode($case, 'approval1');

        $this->sendBack($this->seededUser('u0003'), $case, 'apply');

        $this->assertSame(
            [[$apply->id, 'done', $apply->workflow_case_history_id, []], [$approval1->id, 'done', $approval1->workflow_case_history_id, []]],
            $this->changesOf($this->latestHistory($case)),
        );
    }

    /**
     * 再申請は承認 1 の前の状態(差戻しで到達していないに戻った = ルートの深さ/案件の操作/操作.md 10.4)を写してから到達させる。
     */
    public function test_a_reapplication_records_the_first_approval_as_it_was_before(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');

        $this->reapply($this->seededUser('u0001'), $case);

        $this->assertSame([[$this->caseNode($case, 'approval1')->id, 'unreached', null, []]], $this->changesOf($this->latestHistory($case)));
    }

    public function test_a_pull_back_points_to_the_row_it_reverts_and_has_no_node_changes(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->operate(ApproveCase::class, $this->seededUser('u0002'), $case);
        $approval = $this->latestHistory($case);

        $this->operate(PullBackCase::class, $this->seededUser('u0002'), $case);

        $pullBack = $this->latestHistory($case);
        $this->assertSame([WorkflowAction::PullBack, null, $approval->id], [$pullBack->action, $pullBack->node_changes, $pullBack->reverted_workflow_case_history_id]);
    }

    public function test_a_pull_back_of_a_send_back_points_to_the_send_back(): void
    {
        $case = $this->applyAs($this->seededUser('u0001'));
        $this->sendBack($this->seededUser('u0002'), $case, 'apply');
        $sendBack = $this->latestHistory($case);

        $this->operate(PullBackCase::class, $this->seededUser('u0002'), $case);

        $this->assertSame($sendBack->id, $this->latestHistory($case)->reverted_workflow_case_history_id);
    }

    private function latestHistory(WorkflowCase $case): WorkflowCaseHistory
    {
        return WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderByDesc('id')->firstOrFail();
    }

    /**
     * 履歴の行の node_changes の要素 [ノード, 前の状態, 前の履歴の行, 前の処理対象者](保存した JSON の並びのまま)。
     *
     * @return list<array{mixed, mixed, mixed, mixed}>
     */
    private function changesOf(WorkflowCaseHistory $history): array
    {
        $this->assertIsArray($history->node_changes);

        return array_values(array_map(fn (array $change): array => [$change['node'] ?? null, $change['state'] ?? null, $change['history'] ?? null, $change['targets'] ?? null], $history->node_changes));
    }
}
