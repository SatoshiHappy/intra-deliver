<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * AI の実行の見積もりと額の扱い(式の正本 = docs の 設計/AI/実行と費用.md 5.2)。額は小数 6 桁の文字列(表の numeric(12,6) と同じ)。
 * 依頼の口と、予算の区分の状態の読み取りが同じ計算を使う。
 *
 * @phpstan-import-type PriceTier from AiSettings
 */
class RunEstimates
{
    private const PER_TOKENS = 1_000_000;

    private const MICROS_PER_USD = 1_000_000;

    public function __construct(private AiSettings $settings) {}

    /**
     * 依頼の時点の見積もり。
     *
     * @throws LogicException 単価が無いとき
     */
    public function estimate(string $provider, string $model, int $chars, int $maxOutputTokens): string
    {
        $inputTokens = (int) ceil($chars * $this->settings->charsFactor()) + $this->settings->overheadTokens();
        $tier = $this->tierFor($provider, $model, $inputTokens);

        return self::usd(($inputTokens * $tier['input'] + $maxOutputTokens * $tier['output']) / self::PER_TOKENS);
    }

    /**
     * 予算の区分の見積もりの上限 = 区分に属する種類の、入力の上限の文字数と出力の上限で見積もった額の最大。種類が無ければ 0。
     *
     * @throws LogicException 単価が無いとき
     */
    public function ceilingOf(string $budget): string
    {
        $ceiling = 0;

        foreach ($this->settings->kindCodes() as $code) {
            $kind = $this->settings->kind($code);

            if ($kind !== null && $kind['budget'] === $budget) {
                $chars = $kind['max_input_chars'];
                $estimate = $this->estimate($kind['provider'], $kind['model'], $chars, $kind['max_output_tokens']);
                $ceiling = max($ceiling, self::micros($estimate));
            }
        }

        return self::usd($ceiling / self::MICROS_PER_USD);
    }

    /**
     * 入力のトークンが収まる最初の段。
     *
     * @return PriceTier
     *
     * @throws LogicException 単価が無い・どの段にも収まらないとき
     */
    public function tierFor(string $provider, string $model, int $inputTokens): array
    {
        foreach ($this->settings->priceTiers($provider, $model) as $tier) {
            if ($tier['up_to_input_tokens'] === null || $inputTokens <= $tier['up_to_input_tokens']) {
                return $tier;
            }
        }

        throw new LogicException("接続先 {$provider} のモデル {$model} の単価が無い(config/ai_runs.php の pricing)");
    }

    /** 額を小数 6 桁の文字列にする */
    public static function usd(float $amount): string
    {
        return number_format($amount, 6, '.', '');
    }

    /** 小数 6 桁の額を 100 万分の 1 ドルの整数にする(比べるため) */
    public static function micros(string $amount): int
    {
        return (int) round(((float) $amount) * self::MICROS_PER_USD);
    }
}
