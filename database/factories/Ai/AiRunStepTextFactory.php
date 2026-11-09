<?php

namespace Database\Factories\Ai;

use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiRunStepText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRunStepText>
 */
class AiRunStepTextFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_run_step_id' => AiRunStep::factory(),
            'sent_text' => '{"messages":[{"role":"user","content":"会議を探して"}]}',
            'output_text' => null,
        ];
    }
}
