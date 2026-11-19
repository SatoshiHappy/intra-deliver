<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\ResponseFinish;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\TextUsage;

/**
 * SDK の使用量と終わりの理由を約束の形に写す(使用量の鍵 = 会話の保存の項目の使用量と同じ)。
 *
 * @phpstan-import-type Usage from ConversationItem
 */
final class SubstituteUsage
{
    /**
     * @return Usage
     */
    public static function of(TextUsage $usage): array
    {
        return [
            'input_tokens' => $usage->inputTokens,
            'output_tokens' => $usage->outputTokens,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens,
            'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
        ];
    }

    public static function finish(FinishReason $reason): ResponseFinish
    {
        return ResponseFinish::from($reason->value);
    }
}
