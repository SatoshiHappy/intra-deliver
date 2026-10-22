<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\FailedRun;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\RunEstimates;
use LogicException;

/**
 * AI の実行の費用(docs の 設計/AI/実行と費用.md 5.1。文章はトークンの段、文字起こしは音声の分単価)。額は小数 6 桁の文字列(表の numeric(12,6) と同じ)。見積もり(5.2)は {@see RunEstimates}。
 *
 * @phpstan-import-type PriceTier from AiSettings
 */
class RunCosts
{
    private const PER_TOKENS = 1_000_000;

    private const SECONDS_PER_MINUTE = 60;

    public function __construct(private RunEstimates $estimates, private AiSettings $settings) {}

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
     * LLM の応答の使用量・使った単価の段・費用(表 ai_runs の列)と原文。不完全の理由は応答の終わりの理由から決める。
     *
     * @throws LogicException 単価が無いとき
     */
    public function promptResult(string $provider, string $model, LlmResult $result): RunResult
    {
        $tier = $this->estimates->tierFor($provider, $model, $result->inputTokens);
        $cost = $this->costOf(
            $tier,
            $result->inputTokens,
            $result->outputTokens,
            $result->cacheReadInputTokens,
            $result->cacheWriteInputTokens,
        );
        $incompleteReason = match (true) {
            $result->isTruncated() => FailedRun::OUTPUT_TRUNCATED,
            $result->isRefused() => FailedRun::CONTENT_REFUSED,
            default => null,
        };

        return new RunResult([
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cache_read_input_tokens' => $result->cacheReadInputTokens,
            'cache_write_input_tokens' => $result->cacheWriteInputTokens,
            'reasoning_tokens' => $result->reasoningTokens,
            'pricing' => $tier,
            'cost_usd' => $cost,
        ], $result->text, $incompleteReason);
    }

    /**
     * 文字起こしの費用 = 呼んだ区切りの長さの和 ÷ 60 ×(入力の分単価 + 出力の分単価)(失敗した区切りも数える = 5.1)と、
     * 使用量(区切りのトークンの和。分からなければ入れない)・単価の写し・原文。
     *
     * @param  array{int, int}|null  $tokens  入力と出力のトークンの和(呼んだ区切りのすべてが応答を返したときだけ)
     *
     * @throws LogicException 分単価が無いとき
     */
    public function transcriptionResult(
        string $provider,
        string $model,
        float $calledSeconds,
        ?array $tokens,
        ?string $outputText,
        ?string $incompleteReason = null,
    ): RunResult {
        $price = $this->settings->audioMinutePrice($provider, $model)
            ?? throw new LogicException("接続先 {$provider} のモデル {$model} の音声の分単価が無い(config/ai_runs.php の pricing)");
        $usage = $tokens === null ? [] : ['input_tokens' => $tokens[0], 'output_tokens' => $tokens[1]];

        return new RunResult([
            ...$usage,
            'pricing' => ['per_audio_minute' => $price],
            'cost_usd' => RunEstimates::usd($calledSeconds / self::SECONDS_PER_MINUTE * array_sum($price)),
        ], $outputText, $incompleteReason);
    }
}
