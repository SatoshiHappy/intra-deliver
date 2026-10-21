<?php

namespace Database\Factories\Workflow;

use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は新しい一時保存の案件に付けた、新しい実体の添付。
 *
 * @extends Factory<WorkflowCaseDraftAttachment>
 */
class WorkflowCaseDraftAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_case_id' => WorkflowCase::factory()->draft(),
            'upload_id' => Upload::factory(),
            'name' => fake()->word().'.pdf',
        ];
    }
}
