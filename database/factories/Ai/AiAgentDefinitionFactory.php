<?php

namespace Database\Factories\Ai;

use App\Models\Ai\AiAgentDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は道具とナレッジの無い定義。
 *
 * @extends Factory<AiAgentDefinition>
 */
class AiAgentDefinitionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'テストのエージェント',
            'instructions' => '',
            'tools' => [],
            'knowledge' => [],
        ];
    }
}
