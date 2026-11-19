<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\ProviderTraits;
use App\Queries\Ai\ProviderTraitsSettings;

/**
 * 設定の性質の値を、中核の接続先の性質の型に写す(繋ぎが作る = docs の 設計/AI/会話の中核.md 13.6)。
 */
final readonly class ProviderTraitsOf
{
    public function __construct(private ProviderTraitsSettings $settings) {}

    public function of(string $provider, string $model): ProviderTraits
    {
        $values = $this->settings->values($provider, $model);

        return new ProviderTraits(
            cachesInstructions: $values['caches_instructions'],
            cachesHistory: $values['caches_history'],
            estimateFactor: $values['estimate_factor'],
            overloadedMarker: $values['overloaded_marker'],
            contextOverflowPattern: $values['context_overflow_pattern'],
            retryHeader: $values['retry_header'],
            tooLongPatterns: $values['too_long_patterns'],
            contextManagementModels: $values['context_management_models'],
        );
    }
}
