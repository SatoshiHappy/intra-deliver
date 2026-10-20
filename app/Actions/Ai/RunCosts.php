<?php

namespace App\Actions\Ai;

use App\Queries\Ai\AiSettings;
use App\Queries\Ai\RunEstimates;
use LogicException;

/**
 * AI の実行の費用(docs の 設計/AI/実行と費用.md 5.1)。額は小数 6 桁の文字列(表の numeric(12,6) と同じ)。見積もり(5.2)は {@see RunEstimates}。
 *
 * @phpstan-import-type PriceTier from AiSettings
 */
class RunCosts
{
    private const PER_TOKENS = 1_000_000;

    public function __construct(private RunEstimates $estimates) {}

    /**
     * 使用量の費用(数え方 = 5.1)。
     *
     * @param  PriceTier  $tier
     */
    public function costOf(array $tier, int $inputTokens, int $outputTokens, ?int $cacheReadTokens, ?int $cacheWriteTokens): string
    {
        $uncached = $inputTokens - ($cacheReadTokens ?? 0) - ($cacheWriteTokens ?? 0);

        return RunEstimates::usd((
            $uncached * $tier['input']
            + ($cacheReadTokens ?? 0) * $tier['cache_read']
            + ($cacheWriteTokens ?? 0) * $tier['cache_write']
            + $outputTokens * $tier['output']
        ) / self::PER_TOKENS);
    }

    /**
     * 応答の使用量・使った単価の段・費用(表 ai_runs の列)。
     *
     * @return array{input_tokens: int, output_tokens: int, cache_read_input_tokens: int|null, cache_write_input_tokens: int|null, reasoning_tokens: int|null, pricing: PriceTier, cost_usd: string}
     *
     * @throws LogicException 単価が無いとき
     */
    public function usageAttributes(string $provider, string $model, LlmResult $result): array
    {
        $tier = $this->estimates->tierFor($provider, $model, $result->inputTokens);

        return [
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cache_read_input_tokens' => $result->cacheReadInputTokens,
            'cache_write_input_tokens' => $result->cacheWriteInputTokens,
            'reasoning_tokens' => $result->reasoningTokens,
            'pricing' => $tier,
            'cost_usd' => $this->costOf($tier, $result->inputTokens, $result->outputTokens, $result->cacheReadInputTokens, $result->cacheWriteInputTokens),
        ];
    }
}
