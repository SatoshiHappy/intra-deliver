<?php

namespace Tests\Feature\Seeders;

use App\Actions\Ai\Api\AgentDefinitionCandidates;
use App\Actions\Ai\Conversation\DefinitionToolSize;
use App\Models\Ai\AiAgentDefinition;
use Database\Seeders\AiAgentDefinitionSeeder;
use Tests\Support\TransactionalTestCase;

/**
 * 既定のエージェントの定義の初期データ(docs の 設計/AI/設定とプロンプト/README.md 11.1): 1 件で、道具とナレッジは候補の全部、
 * 外した後の道具の定義の大きさが上限に収まる(11.3)。
 */
final class AiAgentDefinitionSeederTest extends TransactionalTestCase
{
    public function test_seeds_one_default_definition_with_every_candidate(): void
    {
        $definitions = AiAgentDefinition::query()->get();
        $tools = array_map(static fn ($tool): string => $tool->name, app(AgentDefinitionCandidates::class)->tools());
        $knowledge = array_column(app(AgentDefinitionCandidates::class)->knowledge(), 'code');

        $this->assertCount(1, $definitions);
        $definition = $definitions->firstOrFail();
        $this->assertSame(
            [AiAgentDefinitionSeeder::NAME, AiAgentDefinitionSeeder::INSTRUCTIONS, $tools, $knowledge],
            [$definition->name, $definition->instructions, $definition->tools, $definition->knowledge],
        );
        $this->assertNotSame([], $knowledge);
        $this->assertFalse(app(DefinitionToolSize::class)->exceeds($definition));
    }
}
