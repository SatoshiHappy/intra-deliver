<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * モジュール Ai の設定 config/ai_runs.php を読む(docs の 設計/AI/実行と費用.md 5 節)。形が合わない設定は作りの誤り(LogicException)。
 * 種類の設定 kinds は {@see KindSettings}、種類によらない文字起こしの値 transcription は {@see TranscriptionSettings} が読み、ほかをここで読む。
 *
 * @phpstan-type PriceTier array{up_to_input_tokens: int|null, input: float, output: float, cache_read: float, cache_write: float}
 * @phpstan-type AudioMinutePrice array{input: float, output: float}
 */
class AiSettings
{
    /** 送り直しの待ちの上限(ライブラリは 10 秒以内の待ちの指示にだけ従う) */
    private const RETRY_WAIT_SECONDS = 10;

    /**
     * 接続先(config/ai.php の providers の名前)の driver。無ければ null。
     */
    public function providerDriver(string $provider): ?string
    {
        $driver = config("ai.providers.{$provider}.driver");

        return is_string($driver) ? $driver : null;
    }

    /**
     * 接続先・モデルの単価の段の一覧(入力のトークンの上限の小さい順)。単価が無ければ空。
     *
     * @return list<PriceTier>
     */
    public function priceTiers(string $provider, string $model): array
    {
        $tiers = $this->price($provider, $model);

        if (! is_array($tiers) || array_key_exists('per_audio_minute', $tiers)) {
            return [];
        }

        return array_values(array_map(fn (mixed $tier): array => $this->priceTier($tier, "{$provider}.{$model}"), $tiers));
    }

    /**
     * 接続先・モデルの音声の分単価(1 分あたりの米ドル)。分単価でなければ null。
     *
     * @return AudioMinutePrice|null
     */
    public function audioMinutePrice(string $provider, string $model): ?array
    {
        $price = $this->price($provider, $model);
        $perMinute = is_array($price) ? ($price['per_audio_minute'] ?? null) : null;
        $label = "pricing.{$provider}.{$model}.per_audio_minute";

        return is_array($perMinute) ? [
            'input' => $this->number($perMinute['input'] ?? null, "{$label}.input"),
            'output' => $this->number($perMinute['output'] ?? null, "{$label}.output"),
        ] : null;
    }

    /**
     * 予算の区分の名前(設定 budget の鍵)。
     *
     * @return list<string>
     */
    public function budgetNames(): array
    {
        return array_map('strval', array_keys($this->table('budget')));
    }

    /**
     * 予算の区分の期間の上限(米ドル。$period = daily_usd・monthly_usd・total_usd)。null は上限なし。
     * 数と数の文字列のほかは作りの誤り(上限なしに倒さない)。
     *
     * @throws LogicException 設定に無い区分・数でない値のとき
     */
    public function budget(string $budget, string $period): ?float
    {
        $limits = $this->table('budget')[$budget] ?? null;

        if (! is_array($limits)) {
            throw new LogicException("config/ai_runs.php の budget の区分 {$budget} が無いか配列でない");
        }

        $value = $limits[$period] ?? null;

        if ($value === null) {
            return null;
        }

        return $this->number(is_numeric($value) ? (float) $value : $value, "budget.{$budget}.{$period}");
    }

    /**
     * ジョブの時間(秒) = (呼び出しの時間の上限 + 待ち 10 秒)× 2(送り直しの 1 回を含む。docs の 設計/AI/実行と費用.md 4.1)。
     */
    public static function jobTimeout(int $callTimeoutSeconds): int
    {
        return ($callTimeoutSeconds + self::RETRY_WAIT_SECONDS) * 2;
    }

    /**
     * キューが走っている仕事を配り直すまでの時間(秒。config/queue.php の database の retry_after)。
     *
     * @throws LogicException 正の整数でないとき
     */
    public function queueRetryAfter(): int
    {
        $seconds = config('queue.connections.database.retry_after');

        if (! is_int($seconds) || $seconds <= 0) {
            throw new LogicException('config/queue.php の connections.database.retry_after が正の整数でない');
        }

        return $seconds;
    }

    /** 見積もりの係数(送る文の 1 文字あたりの入力のトークン) */
    public function charsFactor(): float
    {
        return $this->number($this->table('estimate')['chars_factor'] ?? null, 'estimate.chars_factor');
    }

    /** 見積もりの上乗せ(入力のトークン) */
    public function overheadTokens(): int
    {
        return (int) $this->number($this->table('estimate')['overhead_tokens'] ?? null, 'estimate.overhead_tokens');
    }

    /**
     * 原文の保存期間(日。正の整数)。
     *
     * @throws LogicException 正の整数でないとき(0 日で全部を消す道を残さない)
     */
    public function retentionDays(): int
    {
        $days = config('ai_runs.retention_days');

        return is_int($days) && $days > 0 ? $days : throw new LogicException('config/ai_runs.php の retention_days が正の整数でない');
    }

    /** 接続先・モデルの単価の設定の値(段の一覧か分単価。無ければ null) */
    private function price(string $provider, string $model): mixed
    {
        $byProvider = $this->table('pricing')[$provider] ?? null;

        return is_array($byProvider) ? ($byProvider[$model] ?? null) : null;
    }

    /**
     * @return PriceTier
     */
    private function priceTier(mixed $tier, string $label): array
    {
        if (! is_array($tier) || ! array_key_exists('up_to_input_tokens', $tier) || ! ($tier['up_to_input_tokens'] === null || is_int($tier['up_to_input_tokens']))) {
            throw new LogicException("config/ai_runs.php の pricing.{$label} の段の形が合わない");
        }

        return [
            'up_to_input_tokens' => $tier['up_to_input_tokens'],
            'input' => $this->number($tier['input'] ?? null, "pricing.{$label}.input"),
            'output' => $this->number($tier['output'] ?? null, "pricing.{$label}.output"),
            'cache_read' => $this->number($tier['cache_read'] ?? null, "pricing.{$label}.cache_read"),
            'cache_write' => $this->number($tier['cache_write'] ?? null, "pricing.{$label}.cache_write"),
        ];
    }

    private function number(mixed $value, string $label): float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new LogicException("config/ai_runs.php の {$label} が数でない");
        }

        return (float) $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function table(string $key): array
    {
        $value = config("ai_runs.{$key}");

        if (! is_array($value)) {
            throw new LogicException("config/ai_runs.php の {$key} が配列でない");
        }

        return $value;
    }
}
