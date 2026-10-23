<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\PromptKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * Ai が LLM を呼ぶ唯一のエージェント。laravel/ai は出力の上限をエージェントの maxTokens() か属性からしか取らず、
 * 無ければ Anthropic の接続の口の既定の 64,000 を送る(vendor の laravel/ai の Gateway/Anthropic/Concerns/BuildsTextRequests.php)ので、
 * 実行の max_output_tokens を返す。構造化出力の形は種類の実装から得る。道具は渡さない(設計/AI/データと失敗と検査.md 6 節)。
 * テストは PromptAgent::fake()・assertPrompted()(laravel/ai の Promptable)で応答を作る。
 */
final class PromptAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    /**
     * @param  string|null  $providerDriver  種類の接続先の driver(config/ai.php)
     * @param  array<string, mixed>  $providerOptions  接続先の要求にそのまま足す指定(種類の設定の provider_options と、実行ごとの訪問者の ID)
     */
    public function __construct(
        private readonly string $systemText,
        private readonly PromptKind $kind,
        private readonly int $maxOutputTokens,
        private readonly int $timeoutSeconds,
        private readonly ?string $providerDriver = null,
        private readonly array $providerOptions = [],
    ) {}

    public function instructions(): string
    {
        return $this->systemText;
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->kind->schema($schema);
    }

    /**
     * 接続先の要求に足す指定(思考の量 output_config.effort・訪問者の ID の metadata など)。種類の接続先のときだけ返す(種類ごとに接続先は 1 つ)。
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        return ($provider instanceof Lab ? $provider->value : $provider) === $this->providerDriver ? $this->providerOptions : [];
    }

    /** 接続先に送る出力の上限(実行の max_output_tokens) */
    public function maxTokens(): int
    {
        return $this->maxOutputTokens;
    }

    /** LLM の呼び出しの時間の上限(秒。種類の設定の timeout_seconds) */
    public function timeout(): int
    {
        return $this->timeoutSeconds;
    }
}
