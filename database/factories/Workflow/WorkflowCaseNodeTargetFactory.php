<?php

namespace Database\Factories\Workflow;

use App\Models\Master\User;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowCaseNodeTarget>
 */
class WorkflowCaseNodeTargetFactory extends Factory
{
    /**
     * Define the model's default state(現在のノードの処理対象者).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_case_node_id' => WorkflowCaseNode::factory()->current(),
            'user_id' => User::factory(),
        ];
    }
}
