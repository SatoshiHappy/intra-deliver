<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * モジュール Ai の設定 config/ai_runs.php を読む 1 か所(docs の 設計/AI/実行と費用.md 5 節)。形が合わない設定は作りの誤り(LogicException)。
 * 種類のコードは . を含むので、config() の . の区切りを通さず kinds の配列から引く。
 *
 * @phpstan-type KindSettings array{provider: string, model: string, budget: string, timeout_seconds: int, max_output_tokens: int, max_input_chars: int, provider_options: array<string, mixed>}
 * @phpstan-type PriceTier array{up_to_input_tokens: int|null, input: float, output: float, cache_read: float, cache_write: float}
 */
class AiSettings
{
    /** 送り直しの待ちの上限(ライブラリは 10 秒以内の待ちの指示にだけ従う) */
    private const RETRY_WAIT_SECONDS = 10;

    /**
     * 設定にある種類のコード。
     *
     * @return list<string>
     */
    public function kindCodes(): array
    {
        return array_map('strval', array_keys($this->table('kinds')));
    }

    /**
     * 種類の設定。設定に無いコードなら null。
     *
     * @return KindSettings|null
     */
    public function kind(string $code): ?array
    {
        $kind = $this->table('kinds')[$code] ?? null;

        if ($kind === null) {
            return null;
        }

        if (! is_array($kind) || ! is_string($kind['provider'] ?? null) || ! is_string($kind['model'] ?? null)
            || ! is_string($kind['budget'] ?? null)
            || ! is_int($kind['timeout_seconds'] ?? null) || $kind['timeout_seconds'] <= 0
            || ! is_int($kind['max_output_tokens'] ?? null) || ! is_int($kind['max_input_chars'] ?? null) || ! is_array($kind['provider_options'] ?? [])) {
            throw new LogicException("config/ai_runs.php の kinds.{$code} の形が合わない");
        }

        return [
            'provider' => $kind['provider'],
            'model' => $kind['model'],
            'budget' => $kind['budget'],
            'timeout_seconds' => $kind['timeout_seconds'],
            'max_output_tokens' => $kind['max_output_tokens'],
            'max_input_chars' => $kind['max_input_chars'],
            'provider_options' => self::stringKeyed($kind['provider_options'] ?? [], "kinds.{$code}.provider_options"),
        ];
    }

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
        $byProvider = $this->table('pricing')[$provider] ?? [];
        $tiers = is_array($byProvider) ? ($byProvider[$model] ?? []) : [];

        return array_values(array_map(fn (mixed $tier): array => $this->priceTier($tier, "{$provider}.{$model}"), is_array($tiers) ? $tiers : []));
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

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values, string $label): array
    {
        $keyed = [];

        foreach ($values as $key => $value) {
            $keyed[is_string($key) ? $key : throw new LogicException("config/ai_runs.php の {$label} の鍵が文字列でない")] = $value;
        }

        return $keyed;
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
