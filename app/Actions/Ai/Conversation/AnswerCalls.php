<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\ToolCalls\ToolWriteProgress;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use LogicException;

/**
 * 「実行」と答えた答えの実行の承認した件(docs の 設計/AI/エージェントの実行/README.md 9.3 の H1)。前の実行の確認待ちの道具の行を呼び出しの
 * id で引き、書く時の部品が件をまたいで積む物を持つ。
 */
final readonly class AnswerCalls
{
    /**
     * @param  AiAgentDefinition  $definition  答えの実行の始めに読んだ定義
     * @param  array<string, AiToolCall>  $rows  呼び出しの id → 前の実行の確認待ちの道具の行(呼び出しの順)
     * @param  array<int, AiToolCallText>  $texts  行の id → 原文の行(答えの実行の始めに確かめた物)
     */
    public function __construct(
        public AiAgentDefinition $definition,
        public array $rows,
        public array $texts,
        public ToolWriteProgress $progress = new ToolWriteProgress,
    ) {}

    public function has(string $callId): bool
    {
        return isset($this->rows[$callId]);
    }

    public function text(AiToolCall $row): ?AiToolCallText
    {
        return $this->texts[$row->id] ?? null;
    }

    /**
     * @throws LogicException 承認した件でないとき(作りの誤り)
     */
    public function row(string $callId): AiToolCall
    {
        return $this->rows[$callId] ?? throw new LogicException("呼び出し {$callId} は承認した件でない");
    }
}
