<?php

namespace Database\Factories\Workflow;

use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 版は作らない(版の factory・案件の factory が作る)。
 *
 * @extends Factory<WorkflowFlow>
 */
class WorkflowFlowFactory extends Factory
{
    /**
     * Define the model's default state(参照者なし).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('flow_????####'),
            'name' => fake()->words(2, true),
            'description' => null,
            'workflow_content_id' => WorkflowContent::factory(),
            'authz_subject_group_id' => null,
            'sort_key' => 0,
        ];
    }

    /**
     * 参照者の指定(新しい用途 selection の対象者)を持たせる。
     */
    public function withReferrers(): static
    {
        return $this->state(fn (): array => ['authz_subject_group_id' => AuthzSubjectGroup::factory()->state(['purpose' => SubjectGroupPurpose::Selection])]);
    }
}
