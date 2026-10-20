<?php

namespace Database\Factories\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowCaseEdge>
 */
class WorkflowCaseEdgeFactory extends Factory
{
    /**
     * Define the model's default state(同じ案件に新しく作る 2 つのノードの間の、条件の無い辺).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_case_id' => WorkflowCase::factory()->active(),
            'from_workflow_case_node_id' => fn (array $attributes): int => WorkflowCaseNode::factory()->createOne(['workflow_case_id' => $attributes['workflow_case_id']])->id,
            'to_workflow_case_node_id' => fn (array $attributes): int => WorkflowCaseNode::factory()->createOne(['workflow_case_id' => $attributes['workflow_case_id']])->id,
            'sort_key' => 0,
            'label' => null,
            'condition' => null,
            'taken' => null,
        ];
    }

    /**
     * 写した分岐の条件と枝の名前を持たせる(分岐開始から出る辺の形)。
     */
    public function conditional(): static
    {
        return $this->state(fn (): array => [
            'label' => '予算 300 万円以上',
            'condition' => ['all' => [['field' => 'budget', 'op' => 'ge', 'value' => 300]]],
        ]);
    }
}
