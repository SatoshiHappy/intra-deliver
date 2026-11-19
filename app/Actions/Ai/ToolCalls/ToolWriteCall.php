<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentCreateTool;
use App\Actions\Ai\Api\AgentUpdateTool;
use App\Actions\Ai\Api\RecordConfirmation;
use App\Models\Ai\AiToolCall;

/**
 * 書く時の部品が通す承認した件 1 つ(道具の行・元の呼び出し・登録から引いた書く道具・書く値の引数・確認の内容)。
 */
final readonly class ToolWriteCall
{
    /**
     * @param  array<string, mixed>  $written  問うの時に残した書く値の引数
     */
    public function __construct(
        public AiToolCall $row,
        public ModelToolCall $call,
        public AgentCreateTool|AgentUpdateTool $tool,
        public array $written,
        public RecordConfirmation $confirmation,
    ) {}
}
