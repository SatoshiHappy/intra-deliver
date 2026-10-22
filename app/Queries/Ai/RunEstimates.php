<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * AI の実行の見積もりと額の扱い(式の正本 = docs の 設計/AI/実行と費用.md 5.2。文章と文字起こし)。額は小数 6 桁の文字列(表の numeric(12,6) と同じ)。
 * 依頼の口と、予算の区分の状態の読み取りが同じ計算を使う。
 *
 * @phpstan-import-type PriceTier from AiSettings
 * @phpstan-import-type AudioMinutePrice from AiSettings
 */
class RunEstimates
{
    private const PER_TOKENS = 1_000_000;

    private const MICROS_PER_USD = 1_000_000;

    private const SECONDS_PER_MINUTE = 60;

    public function __construct(private AiSettings $settings, private KindSettings $kinds) {}

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
     * 文字起こしの依頼の時点の見積もり(測った長さで数えるので、実費とほぼ同じ = 設計/AI/実行と費用.md 5.2)。
     *
     * @throws LogicException 分単価が無いとき
     */
    public function transcriptionEstimate(string $provider, string $model, float $audioSeconds): string
    {
        return self::audioCost($this->audioMinutePriceOf($provider, $model), $audioSeconds);
    }

    /**
     * 接続先・モデルの音声の分単価(見積もりと実費が同じ 1 か所から引く)。
     *
     * @return AudioMinutePrice
     *
     * @throws LogicException 分単価が無いとき
     */
    public function audioMinutePriceOf(string $provider, string $model): array
    {
        return $this->settings->audioMinutePrice($provider, $model)
            ?? throw new LogicException("接続先 {$provider} のモデル {$model} の音声の分単価が無い(config/ai_runs.php の pricing)");
    }

    /**
     * 音声の秒数の額(見積もりと実費が同じ式を使う = 設計/AI/実行と費用.md 5.1・5.2)。
     *
     * @param  AudioMinutePrice  $price
     */
    public static function audioCost(array $price, float $seconds): string
    {
        return self::usd($seconds / self::SECONDS_PER_MINUTE * ($price['input'] + $price['output']));
    }

    /**
     * 予算の区分の見積もりの上限 = 区分に属する種類の 1 依頼の見積もりの上限の最大。種類が無ければ 0。文章の種類は入力の上限の文字数と
     * 出力の上限で、文字起こしの種類は 1 本の長さの上限で見積もる。
     *
     * @throws LogicException 単価が無いとき
     */
    public function ceilingOf(string $budget): string
    {
        $ceiling = 0;

        foreach ($this->kinds->codes() as $code) {
            $estimate = $this->kindCeiling($code, $budget);
            $ceiling = max($ceiling, $estimate === null ? 0 : self::micros($estimate));
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

    /** 種類の 1 依頼の見積もりの上限。区分が違えば null */
    private function kindCeiling(string $code, string $budget): ?string
    {
        $prompt = $this->kinds->prompt($code);
        $transcription = $this->kinds->transcription($code);

        return match (true) {
            $prompt !== null && $prompt['budget'] === $budget => $this->estimate(
                $prompt['provider'], $prompt['model'], $prompt['max_input_chars'], $prompt['max_output_tokens'],
            ),
            $transcription !== null && $transcription['budget'] === $budget => $this->transcriptionEstimate(
                $transcription['provider'], $transcription['model'], $transcription['max_audio_seconds'],
            ),
            default => null,
        };
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
