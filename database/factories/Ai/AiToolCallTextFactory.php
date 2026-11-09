<?php

namespace Database\Factories\Ai;

use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiToolCallText>
 */
class AiToolCallTextFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_tool_call_id' => AiToolCall::factory(),
            'arguments' => ['query' => '会議'],
            'result_text' => '会議が 1 件見つかった',
            'confirmation' => null,
            'record_names' => [],
        ];
    }
}
