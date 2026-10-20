<?php

namespace Database\Factories\Workflow;

use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowContent>
 */
class WorkflowContentFactory extends Factory
{
    /**
     * Define the model's default state(親の無い業務モジュールを申請フォームにする).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('content_????####'),
            'name' => fake()->words(2, true),
            'record_module_id' => RecordModule::factory(),
        ];
    }
}
