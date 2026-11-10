<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * AI の実行の見積もりと額の扱い(式の正本 = docs の 設計/AI/実行と費用/接続先と費用.md 5.2。文章と文字起こし)。額は小数 6 桁の文字列(表の numeric(12,6) と同じ)。
 * 依頼の口と、予算の区分の状態の読み取りが同じ計算を使う。会話の型の 1 依頼の見積もりの上限(1 ステップの見積もりの上限の最大)は、依頼の口の先の断りと
 * 予算の区分の状態には足さない(0 で数える = 5.3 の「1 依頼の見積もりの上限」)。
 *
 * @phpstan-import-type PriceTier from AiSettings
 * @phpstan-import-type AudioMinutePrice from AiSettings
 * @phpstan-import-type PromptKindSettings from KindSettings
 * @phpstan-import-type TranscriptionKindSettings from KindSettings
 * @phpstan-import-type ConversationKindSettings from KindSettings
 * @phpstan-import-type AnyKindSettings from KindSettings
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
     * 文字起こしの依頼の時点の見積もり(測った長さで数えるので、実費とほぼ同じ = 設計/AI/実行と費用/接続先と費用.md 5.2)。
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
     * 音声の秒数の額(見積もりと実費が同じ式を使う = 設計/AI/実行と費用/接続先と費用.md 5.1・5.2)。
     *
     * @param  AudioMinutePrice  $price
     */
    public static function audioCost(array $price, float $seconds): string
    {
        return self::usd($seconds / self::SECONDS_PER_MINUTE * ($price['input'] + $price['output']));
    }

    /**
     * 予算の区分の見積もりの上限 = 区分に属する種類の 1 依頼の見積もりの上限({@see self::ceilingOfKind()})の最大。種類が無ければ 0。
     *
     * @throws LogicException 単価が無いとき
     */
    public function ceilingOf(string $budget): string
    {
        $kinds = [];

        foreach ($this->kinds->codes() as $code) {
            $kinds[$code] = $this->kinds->settings($code);
        }

        return $this->ceilingAmong($budget, array_filter($kinds));
    }

    /**
     * 渡した種類の設定のうち、区分に属するものの 1 依頼の見積もりの上限の最大(種類が無ければ 0)。
     *
     * @param  array<string, AnyKindSettings>  $kinds  コード → 設定
     *
     * @throws LogicException 単価が無いとき
     */
    public function ceilingAmong(string $budget, array $kinds): string
    {
        $ceiling = 0;

        foreach ($kinds as $kind) {
            if ($kind['budget'] === $budget) {
                $ceiling = max($ceiling, self::micros($this->ceilingOfKind($kind)));
            }
        }

        return self::usd($ceiling / self::MICROS_PER_USD);
    }

    /**
     * 種類の設定の 1 依頼の見積もりの上限。文章の種類は入力の上限の文字数と出力の上限で、文字起こしの種類は 1 本の長さの上限で見積もり、
     * 会話の型の種類は 0。
     *
     * @param  AnyKindSettings  $kind
     *
     * @throws LogicException 単価が無いとき
     */
    public function ceilingOfKind(array $kind): string
    {
        return match ($kind['type']) {
            KindSettings::PROMPT => $this->estimate(
                $kind['provider'], $kind['model'], $kind['max_input_chars'], $kind['max_output_tokens'],
            ),
            KindSettings::TRANSCRIPTION => $this->transcriptionEstimate(
                $kind['provider'], $kind['model'], $kind['max_audio_seconds'],
            ),
            KindSettings::CONVERSATION => self::usd(0),
        };
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
