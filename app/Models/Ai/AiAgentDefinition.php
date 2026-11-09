<?php

namespace App\Models\Ai;

use Database\Factories\Ai\AiAgentDefinitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * AI のエージェントの定義(表 ai_agent_definitions。docs の 設計/AI/設定とプロンプト/README.md 11.1)。会話の型の実行が指す(定義を消すと、
 * 指す実行がある間は断られる)。道具は道具の名前の配列、ナレッジは業務モジュールのコードの配列。
 */
#[Fillable(['name', 'instructions', 'tools', 'knowledge'])]
class AiAgentDefinition extends Model
{
    /** @use HasFactory<AiAgentDefinitionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'knowledge' => 'array',
        ];
    }
}
