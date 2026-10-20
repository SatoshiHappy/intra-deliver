<?php

namespace Database\Factories\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseSendBack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowCaseSendBack>
 */
class WorkflowCaseSendBackFactory extends Factory
{
    /**
     * Define the model's default state(同じ案件に先に作るノードを先、後に作るノードを差戻し元にする).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_case_id' => WorkflowCase::factory()->active(),
            'target_workflow_case_node_id' => fn (array $attributes): int => WorkflowCaseNode::factory()->createOne(['workflow_case_id' => $attributes['workflow_case_id']])->id,
            'workflow_case_node_id' => fn (array $attributes): int => WorkflowCaseNode::factory()->createOne(['workflow_case_id' => $attributes['workflow_case_id']])->id,
        ];
    }
}
