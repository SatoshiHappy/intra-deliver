<?php

namespace Database\Factories\Workflow;

use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowCaseAssignment>
 */
class WorkflowCaseAssignmentFactory extends Factory
{
    /**
     * Define the model's default state(同じ案件の、処理の時に指定のノードへの 1 番目の指定。指定した行 = そのノードの指定するノードの承認の行).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_case_id' => WorkflowCase::factory()->active(),
            'workflow_case_node_id' => fn (array $attributes): int => WorkflowCaseNode::factory()->designated()->createOne(['workflow_case_id' => $attributes['workflow_case_id']])->id,
            'workflow_case_history_id' => fn (array $attributes): int => WorkflowCaseHistory::factory()->createOne([
                'workflow_case_node_id' => WorkflowCaseNode::query()->whereKey($attributes['workflow_case_node_id'])->sole()->designator_workflow_case_node_id,
            ])->id,
            'sequence' => 1,
            'user_id' => User::factory(),
        ];
    }
}
