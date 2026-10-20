<?php

namespace Database\Factories\Workflow;

use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRouteSendBack>
 */
class WorkflowRouteSendBackFactory extends Factory
{
    /**
     * Define the model's default state(同じ版に先に作るノードを先、後に作るノードを差戻し元にする).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_flow_version_id' => WorkflowFlowVersion::factory(),
            'target_workflow_route_node_id' => fn (array $attributes): int => WorkflowRouteNode::factory()->createOne(['workflow_flow_version_id' => $attributes['workflow_flow_version_id']])->id,
            'workflow_route_node_id' => fn (array $attributes): int => WorkflowRouteNode::factory()->createOne(['workflow_flow_version_id' => $attributes['workflow_flow_version_id']])->id,
        ];
    }
}
