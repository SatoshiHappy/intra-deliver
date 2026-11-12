<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\ConversationItem;
use App\Enums\Ai\AiRunStopReason;

/**
 * 道具を呼ぶ包みが 1 つのステップを通した結果: 呼び出しごとの会話の保存に書いた項目と道具の行の id(呼び出しの順)・書いた中断の印・止めた理由・
 * 会話が無いの印(会話の保存の足すが無しを返した。残りの呼び出しは動かしていない)。
 */
final readonly class ToolStepOutcome
{
    /**
     * @param  list<array{callId: string, toolCallId: int, item: ConversationItem}>  $results
     */
    public function __construct(
        public array $results,
        public ?ConversationItem $interruption = null,
        public ?AiRunStopReason $stopReason = null,
        public bool $conversationMissing = false,
    ) {}
}
