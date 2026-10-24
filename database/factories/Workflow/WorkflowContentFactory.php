<?php

namespace Database\Factories\Workflow;

use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowContent>
 */
class WorkflowContentFactory extends Factory
{
    /**
     * Define the model's default state(親の無い業務モジュールを申請フォームにする。申請対象なし).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('content_????####'),
            'name' => fake()->words(2, true),
            'record_module_id' => RecordModule::factory(),
            'target_record_field_id' => null,
            'target_record_module_id' => null,
        ];
    }

    /**
     * 申請対象ありにする(申請書の業務モジュールに他モジュール参照の項目を 1 つ作り、その id と参照先の業務モジュールを 2 列に写す)。
     */
    public function withTarget(): static
    {
        return $this->afterMaking(function (WorkflowContent $content): void {
            $field = RecordField::factory()->reference()->create(['record_module_id' => $content->record_module_id]);
            $content->target_record_field_id = $field->id;
            $content->target_record_module_id = $field->referenced_record_module_id;
        });
    }
}
