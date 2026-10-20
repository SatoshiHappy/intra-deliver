<?php

namespace Database\Factories\Workflow;

use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowProxy;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowProxy>
 */
class WorkflowProxyFactory extends Factory
{
    /**
     * Define the model's default state(全フローの承認の代理。2000-01-01 から上限なし。登録した人 = 代理元とは別の新しいユーザー).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'principal_user_id' => User::factory(),
            'proxy_user_id' => User::factory(),
            'kind' => WorkflowProxyKind::Approve,
            'workflow_flow_id' => null,
            'start_date' => '2000-01-01',
            'end_date' => null,
            'registered_by_user_id' => User::factory(),
        ];
    }

    /**
     * 特定業務代理にする(新しいフロー)。
     */
    public function forFlow(): static
    {
        return $this->state(fn (): array => ['workflow_flow_id' => WorkflowFlow::factory()]);
    }
}
