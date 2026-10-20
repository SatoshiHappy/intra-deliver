<?php

namespace Database\Factories\Workflow;

use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowCaseHistoryUser>
 */
class WorkflowCaseHistoryUserFactory extends Factory
{
    /**
     * Define the model's default state(同じ案件の承認のノードの履歴の行の 1 番目の人).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_case_id' => WorkflowCase::factory()->active(),
            'workflow_case_history_id' => fn (array $attributes): int => $this
                ->historyIn((int) $attributes['workflow_case_id']),
            'sequence' => 1,
            'user_id' => User::factory(),
        ];
    }

    /**
     * 案件の新しい承認のノードの履歴の行。
     */
    private function historyIn(int $caseId): int
    {
        $node = WorkflowCaseNode::factory()->createOne(['workflow_case_id' => $caseId]);

        return WorkflowCaseHistory::factory()->createOne(['workflow_case_node_id' => $node->id])->id;
    }
}
