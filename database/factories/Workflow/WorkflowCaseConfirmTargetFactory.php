<?php

namespace Database\Factories\Workflow;

use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowCaseConfirmTarget>
 */
class WorkflowCaseConfirmTargetFactory extends Factory
{
    /**
     * Define the model's default state(同じ案件の、確認する人を持つノードの確認対象者).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_case_id' => WorkflowCase::factory()->active(),
            'workflow_case_node_id' => fn (array $attributes): int => WorkflowCaseNode::factory()->confirming()
                ->createOne(['workflow_case_id' => $attributes['workflow_case_id']])->id,
            'user_id' => User::factory(),
        ];
    }
}
