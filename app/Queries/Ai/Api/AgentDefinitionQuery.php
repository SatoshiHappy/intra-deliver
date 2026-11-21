<?php

namespace App\Queries\Ai\Api;

use App\Models\Ai\AiAgentDefinition;

/**
 * エージェントの定義の読み取り(docs の 設計/AI/設定とプロンプト/README.md 11.2・設計/AI/公開の口.md 3 節の定義の読み書きの行)。主体の判定を
 * しない(呼び手の業務処理が入口で判定する)。
 */
final readonly class AgentDefinitionQuery
{
    /** @return list<AgentDefinitionView> 作った順 */
    public function list(): array
    {
        return array_values(AiAgentDefinition::query()->orderBy('id')->get()->map(self::view(...))->all());
    }

    public function find(int $id): ?AgentDefinitionView
    {
        $definition = AiAgentDefinition::query()->find($id);

        return $definition === null ? null : self::view($definition);
    }

    private static function view(AiAgentDefinition $definition): AgentDefinitionView
    {
        return new AgentDefinitionView(
            $definition->id,
            $definition->name,
            $definition->instructions,
            array_values(array_filter((array) $definition->tools, is_string(...))),
            array_values(array_filter((array) $definition->knowledge, is_string(...))),
        );
    }
}
