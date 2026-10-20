<?php

namespace Database\Factories\Workflow;

use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRouteEdge>
 */
class WorkflowRouteEdgeFactory extends Factory
{
    /**
     * Define the model's default state(同じ版に新しく作る 2 つのノードの間の、条件の無い辺).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_flow_version_id' => WorkflowFlowVersion::factory(),
            'from_workflow_route_node_id' => fn (array $attributes): int => WorkflowRouteNode::factory()->createOne(['workflow_flow_version_id' => $attributes['workflow_flow_version_id']])->id,
            'to_workflow_route_node_id' => fn (array $attributes): int => WorkflowRouteNode::factory()->createOne(['workflow_flow_version_id' => $attributes['workflow_flow_version_id']])->id,
            'sort_key' => 0,
            'label' => null,
            'condition' => null,
        ];
    }

    /**
     * 分岐の条件と枝の名前を持たせる(分岐開始から出る辺の形。条件は業務データの入れ物の規則の条件の JSON)。
     */
    public function conditional(): static
    {
        return $this->state(fn (): array => [
            'label' => '予算 300 万円以上',
            'condition' => ['all' => [['field' => 'budget', 'op' => 'ge', 'value' => 300]]],
        ]);
    }
}
