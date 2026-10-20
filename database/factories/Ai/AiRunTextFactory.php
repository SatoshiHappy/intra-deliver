<?php

namespace Database\Factories\Ai;

use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiRunText>
 */
class AiRunTextFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_run_id' => AiRun::factory(),
            'system_text' => 'system',
            'user_text' => 'user',
            'output_text' => null,
        ];
    }
}
