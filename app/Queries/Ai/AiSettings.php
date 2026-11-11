<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * モジュール Ai の設定 config/ai_runs.php を読む(docs の 設計/AI/実行と費用/接続先と費用.md 5 節)。形が合わない設定は、値を使う所では作りの誤り
 * (LogicException)、起動の時の確かめ(`*Problems`)では食い違いの文(ほかの確かめを続ける)。確かめの止め方 = docs の
 * 設計/AI/実行と費用/接続先と費用.md 5.4。
 * 形の規則は {@see AiSettingsShape} の 1 か所。
 * 種類の設定 kinds は {@see KindSettings}、種類によらない文字起こしの値 transcription は {@see TranscriptionSettings} が読み、ほかをここで読む。
 *
 * @phpstan-type PriceTier array{up_to_input_tokens: int|null, input: float, output: float, cache_read: float, cache_write: float}
 * @phpstan-type AudioMinutePrice array{input: float, output: float}
 */
class AiSettings
{
    /** 予算の区分の期間(config/ai_runs.php の budget の区分の鍵 → 文の名前。短い順) */
    public const BUDGET_PERIODS = ['daily_usd' => '日', 'monthly_usd' => '月', 'total_usd' => '累計'];

    /** 送り直しの待ちの上限(ライブラリは 10 秒以内の待ちの指示にだけ従う) */
    private const RETRY_WAIT_SECONDS = 10;

    /**
     * 起動の時の確かめの、種類によらない表の形の食い違い(docs の 設計/AI/データと失敗と検査.md 8 節)。
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return AiSettingsShape::problems();
    }

    /**
     * 起動の時の確かめ: 接続先・モデルの単価の形の食い違い。単価が無い・表が配列でない({@see self::problems()} が出す)なら空。
     *
     * @return list<string>
     */
    public function priceProblems(string $provider, string $model): array
    {
        return AiSettingsShape::entryProblems(AiSettingsShape::price($provider, $model)[0], "{$provider}.{$model}");
    }

    /** 形の判定: 予算の区分の表が配列か */
    public function budgetTableFits(): bool
    {
        return self::fits(AiSettingsShape::table('budget'));
    }

    /** 形の判定: キューの配り直すまでの時間が正の整数か */
    public function retryAfterFits(): bool
    {
        return self::fits(AiSettingsShape::retryAfter());
    }

    /** 形の判定: 見積もりの表が配列で、値がどれも数か */
    public function estimateFits(): bool
    {
        $fits = fn (string $key): bool => self::fits(AiSettingsShape::estimate($key));

        return array_all(AiSettingsShape::ESTIMATE_KEYS, $fits);
    }

    /** 形の判定: 予算の区分 $budget が表に在る配列で、期間の上限がどれも数か数の文字列か無し */
    public function budgetLimitsFit(string $budget): bool
    {
        $periods = array_keys(self::BUDGET_PERIODS);

        return array_all($periods, fn (string $period): bool => self::fits(AiSettingsShape::budget($budget, $period)));
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
     *
     * @throws LogicException 表が配列でない・段の形が合わないとき
     */
    public function priceTiers(string $provider, string $model): array
    {
        return self::tiersOf($this->checkedPrice($provider, $model));
    }

    /**
     * 起動の時の確かめ: トークンの単価が、どの入力の量にも当たるか(最後の段に入力の上限が無い。費用を 0 と数えて上限をすり抜けないため)。
     * null = 確かめが止まる(docs の 設計/AI/実行と費用/接続先と費用.md 5.4)。
     */
    public function pricesEveryInput(string $provider, string $model): ?bool
    {
        if (! $this->priceFits($provider, $model)) {
            return null;
        }

        $tiers = self::tiersOf(AiSettingsShape::price($provider, $model)[0]);

        return $tiers !== [] && end($tiers)['up_to_input_tokens'] === null;
    }

    /**
     * 起動の時の確かめ: 音声の分単価があるか(費用を 0 と数えて上限をすり抜けないため)。null = 確かめが止まる(接続先と費用.md 5.4)。
     */
    public function pricesPerAudioMinute(string $provider, string $model): ?bool
    {
        if (! $this->priceFits($provider, $model)) {
            return null;
        }

        $price = AiSettingsShape::price($provider, $model)[0];

        return is_array($price) && AiSettingsShape::isAudioMinutePrice($price);
    }

    /**
     * 接続先・モデルの音声の分単価(1 分あたりの米ドル)。分単価でなければ null。
     *
     * @return AudioMinutePrice|null
     *
     * @throws LogicException 表が配列でない・分単価の形が合わないとき
     */
    public function audioMinutePrice(string $provider, string $model): ?array
    {
        $price = $this->checkedPrice($provider, $model);

        if (! is_array($price) || ! AiSettingsShape::isAudioMinutePrice($price)) {
            return null;
        }

        $perMinute = (array) $price['per_audio_minute'];

        return ['input' => (float) $perMinute['input'], 'output' => (float) $perMinute['output']];
    }

    /**
     * 予算の区分の名前(設定 budget の鍵)。
     *
     * @return list<string>
     *
     * @throws LogicException 表が配列でないとき
     */
    public function budgetNames(): array
    {
        return self::valueOf(AiSettingsShape::budgetNames());
    }

    /**
     * 予算の区分の期間の上限(米ドル。$period = daily_usd・monthly_usd・total_usd)。null は上限なし。
     * 数と数の文字列のほかは作りの誤り(上限なしに倒さない)。
     *
     * @throws LogicException 設定に無い区分・数でない値のとき
     */
    public function budget(string $budget, string $period): ?float
    {
        return self::valueOf(AiSettingsShape::budget($budget, $period));
    }

    /**
     * ジョブの時間(秒) = (呼び出しの時間の上限 + 待ち 10 秒)× 2(送り直しの 1 回を含む。docs の 設計/AI/実行と費用/README.md 4.1)。
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
        return (int) self::valueOf(AiSettingsShape::retryAfter());
    }

    /**
     * 見積もりの係数(送る文の 1 文字あたりの入力のトークン)。
     *
     * @throws LogicException 表が配列でない・数でないとき
     */
    public function charsFactor(): float
    {
        return (float) self::valueOf(AiSettingsShape::estimate('chars_factor'));
    }

    /**
     * 見積もりの上乗せ(入力のトークン)。
     *
     * @throws LogicException 表が配列でない・数でないとき
     */
    public function overheadTokens(): int
    {
        return (int) self::valueOf(AiSettingsShape::estimate('overhead_tokens'));
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
     * 接続先・モデルの単価の設定の値(段の一覧か分単価。無ければ null)。
     *
     * @throws LogicException 表が配列でない・形が合わないとき
     */
    private function checkedPrice(string $provider, string $model): mixed
    {
        $price = self::valueOf(AiSettingsShape::price($provider, $model));
        $problems = AiSettingsShape::entryProblems($price, "{$provider}.{$model}");

        return $problems === [] ? $price : throw new LogicException($problems[0]);
    }

    /** 単価の確かめの止めの判定(単価の表の形 → 項目の形。接続先と費用.md 5.4) */
    private function priceFits(string $provider, string $model): bool
    {
        $checked = AiSettingsShape::price($provider, $model);

        return self::fits($checked) && AiSettingsShape::entryProblems($checked[0], "{$provider}.{$model}") === [];
    }

    /**
     * 形を確かめた単価の設定の値の段の一覧(分単価・無いなら空)。
     *
     * @return list<PriceTier>
     */
    private static function tiersOf(mixed $price): array
    {
        if (! is_array($price) || AiSettingsShape::isAudioMinutePrice($price)) {
            return [];
        }

        return array_values(array_map(fn (mixed $tier): array => self::tierOf((array) $tier), $price));
    }

    /**
     * 値と形の食い違いの組の、形の判定(食い違いが無いか)。
     *
     * @param  array{mixed, list<string>}  $checked
     */
    private static function fits(array $checked): bool
    {
        return $checked[1] === [];
    }

    /**
     * 形を確かめた段の値。
     *
     * @param  array<array-key, mixed>  $tier
     * @return PriceTier
     */
    private static function tierOf(array $tier): array
    {
        $limit = $tier['up_to_input_tokens'];

        return [
            'up_to_input_tokens' => is_int($limit) ? $limit : null,
            'input' => (float) $tier['input'],
            'output' => (float) $tier['output'],
            'cache_read' => (float) $tier['cache_read'],
            'cache_write' => (float) $tier['cache_write'],
        ];
    }

    /**
     * 値と食い違いの文の組の値(文があれば最初の文で作りの誤り)。
     *
     * @template T
     *
     * @param  array{T, list<string>}  $checked
     * @return T
     *
     * @throws LogicException
     */
    private static function valueOf(array $checked): mixed
    {
        [$value, $problems] = $checked;

        return $problems === [] ? $value : throw new LogicException($problems[0]);
    }
}
