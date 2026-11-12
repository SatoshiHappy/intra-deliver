<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\ConversationStore;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;

/**
 * 道具を呼ぶ包みが 1 つのステップの呼び出しを通すのに要る物(実行・応答に呼び出しが入ったステップ・実行の始めに渡した道具・会話の保存の口)。
 */
final readonly class ToolStep
{
    public function __construct(
        public AiRun $run,
        public AiRunStep $step,
        public GrantedTools $tools,
        public ConversationStore $store,
    ) {}
}
