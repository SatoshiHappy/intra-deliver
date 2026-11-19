<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\ProviderTraits;
use App\Enums\Ai\AiConversationPurpose;

/**
 * 用途ごとの送る先(接続先・モデル・接続先の性質。docs の 設計/AI/実行と費用/接続先と費用.md 5 節の「会話の型の用途」)。
 */
final readonly class StepTarget
{
    public function __construct(
        public AiConversationPurpose $purpose,
        public string $provider,
        public string $model,
        public ProviderTraits $traits,
    ) {}
}
