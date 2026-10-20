<?php

namespace Database\Factories\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 確認した人を渡さなければ、そのノードの確認対象者の行を先に作ってその人にする(渡せば確認対象者の行は作らない)。
 * 履歴の行はそのノードの行で、ノードを処理済みにはしない(外部キーの削除の動作を 1 行で確かめられるように)。
 *
 * @extends Factory<WorkflowCaseConfirmation>
 */
class WorkflowCaseConfirmationFactory extends Factory
{
    /**
     * Define the model's default state(同じ案件の、確認する人を持つノードの確認対象者の確認).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_case_id' => WorkflowCase::factory()->active(),
            'workflow_case_node_id' => fn (array $attributes): int => WorkflowCaseNode::factory()->confirming()
                ->createOne(['workflow_case_id' => $attributes['workflow_case_id']])->id,
            'workflow_case_history_id' => fn (array $attributes): int => WorkflowCaseHistory::factory()
                ->createOne(['workflow_case_node_id' => $attributes['workflow_case_node_id']])->id,
            'user_id' => fn (array $attributes): int => WorkflowCaseConfirmTarget::factory()->createOne([
                'workflow_case_id' => $attributes['workflow_case_id'],
                'workflow_case_node_id' => $attributes['workflow_case_node_id'],
            ])->user_id,
        ];
    }
}
