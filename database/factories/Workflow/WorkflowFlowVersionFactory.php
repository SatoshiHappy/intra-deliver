<?php

namespace Database\Factories\Workflow;

use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 番号は作る時点のフローの最大 + 1。期間の既定は 2000-01-01 から上限なし(同じフローに 2 つ目の版を作るときは期間を渡す)。
 *
 * @extends Factory<WorkflowFlowVersion>
 */
class WorkflowFlowVersionFactory extends Factory
{
    /**
     * Define the model's default state(ノードの無い、有効な版).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_flow_id' => WorkflowFlow::factory(),
            'number' => fn (array $attributes): int => (int) WorkflowFlowVersion::query()->where('workflow_flow_id', $attributes['workflow_flow_id'])->max('number') + 1,
            'start_date' => '2000-01-01',
            'end_date' => null,
            'is_deleted' => false,
        ];
    }
}
