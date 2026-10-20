<?php

namespace App\Actions\Ai;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * LLM の応答から Ai が使うもの(内部。laravel/ai の型を {@see LlmClient} の外に出さない)。使用量は laravel/ai の TextUsage
 * (入力はキャッシュの読み書きを含む合計、出力は推論を含む合計)。
 */
final readonly class LlmResult
{
    /**
     * @param  array<array-key, mixed>  $structured
     */
    public function __construct(
        public string $text,
        public array $structured,
        public int $inputTokens,
        public int $outputTokens,
        public ?int $cacheReadInputTokens,
        public ?int $cacheWriteInputTokens,
        public ?int $reasoningTokens,
        public string $finishReason,
    ) {}

    public static function fromResponse(AgentResponse $response): self
    {
        $usage = $response->usage;

        return new self(
            text: $response->text,
            structured: $response instanceof StructuredAgentResponse ? $response->structured : [],
            inputTokens: $usage->inputTokens,
            outputTokens: $usage->outputTokens,
            cacheReadInputTokens: $usage->cacheReadInputTokens,
            cacheWriteInputTokens: $usage->cacheWriteInputTokens,
            reasoningTokens: $usage->reasoningTokens,
            finishReason: ($response->steps->last()->finishReason ?? FinishReason::Unknown)->value,
        );
    }

    /** 出力が上限のトークンで切れた・接続先が応答を断った(docs の 設計/AI/データと失敗と検査.md 7 節の incomplete) */
    public function isIncomplete(): bool
    {
        return $this->isTruncated() || $this->isRefused();
    }

    /** 出力が上限のトークンで切れた(応答の最後の終わりの理由が長さの上限) */
    public function isTruncated(): bool
    {
        return $this->finishReason === FinishReason::Length->value;
    }

    /** 接続先が応答を断った */
    public function isRefused(): bool
    {
        return $this->finishReason === FinishReason::ContentFilter->value;
    }
}
