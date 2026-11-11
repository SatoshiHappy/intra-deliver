<?php

namespace App\Actions\Ai;

use App\Queries\Ai\AiSettings;
use App\Queries\Ai\ConversationSettings;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunEstimates;
use App\Queries\Ai\TranscriptionSettings;

/**
 * 種類と予算の区分の設定の食い違い(`ai:check-settings` とテストが落とす = docs の 設計/AI/データと失敗と検査.md 8 節、実行と費用/README.md 4.1・5 節・5.2)。
 * 確かめの単位・読む値・前提の確かめと止め方 = 設計/AI/実行と費用/接続先と費用.md 5.4(形の食い違いの文はどれも返し、ほかの確かめを続ける)。
 *
 * @phpstan-import-type TranscriptionKindSettings from KindSettings
 * @phpstan-import-type AnyKindSettings from KindSettings
 * @phpstan-import-type AllKindSettings from KindSettings
 */
class KindLimitChecks
{
    /** 文字起こしの 1 本の長さの上限の刻み(超えたときの文が「<N> 時間まで」なので時間の倍数) */
    private const SECONDS_PER_HOUR = 3600;

    public function __construct(
        private AiSettings $settings,
        private TranscriptionSettings $transcription,
        private RunEstimates $estimates,
    ) {}

    /**
     * 全部の種類の設定({@see KindSettings::all()} の組)の、形の食い違いの文と、5.4 の確かめの食い違い。
     *
     * @param  AllKindSettings  $all
     * @return list<string>
     */
    public function problems(array $all): array
    {
        [$kinds, $kindShape] = $all;
        $problems = [...$kindShape, ...$this->settings->problems(), ...$this->transcription->problems()];
        $pricing = [];
        $priced = [];

        foreach ($kinds as $code => $kind) {
            [$found, $passed] = $this->pricingProblems($code, $kind);
            $problems = [...$problems, ...$this->kindProblems($code, $kind)];
            $pricing = [...$pricing, ...$found];
            $priced[$kind['budget']] = ($priced[$kind['budget']] ?? true) && $passed;
        }

        return [...$problems, ...$pricing, ...$this->budgetProblems($all, $priced)];
    }

    /**
     * 種類ごとの食い違い(区分がある・ジョブの時間 < retry_after・文字起こしの値との組)。ジョブの時間が retry_after 以上だと、走っている間に
     * 同じ仕事が 2 回目に配られて費用が 2 回かかる(文章と文字起こし。会話の型のジョブの時間は会話のジョブを足す版で確かめる)。
     *
     * @param  AnyKindSettings  $kind  呼び手が引いた種類の設定
     * @return list<string>
     */
    private function kindProblems(string $code, array $kind): array
    {
        $exists = ! $this->settings->budgetTableFits()
            || in_array($kind['budget'], $this->settings->budgetNames(), true);
        $typed = match ($kind['type']) {
            KindSettings::PROMPT => $this->settings->retryAfterFits()
                ? $this->jobTimeProblems($code, AiSettings::jobTimeout($kind['timeout_seconds']))
                : [],
            KindSettings::TRANSCRIPTION => $this->transcriptionProblems($code, $kind),
            KindSettings::CONVERSATION => [],
        };

        return [
            ...($exists ? [] : ["種類 {$code} の予算の区分 {$kind['budget']} が設定(config/ai_runs.php の budget)に無い"]),
            ...$typed,
        ];
    }

    /**
     * ジョブの時間 $jobTimeout が retry_after 以上なら、その文。
     *
     * @return list<string>
     */
    private function jobTimeProblems(string $code, int $jobTimeout): array
    {
        $retryAfter = $this->settings->queueRetryAfter();

        return $jobTimeout >= $retryAfter
            ? ["種類 {$code} のジョブの時間 {$jobTimeout} 秒がキューの retry_after {$retryAfter} 秒以上(config/queue.php)"]
            : [];
    }

    /**
     * 単価の食い違いと、確かめが通ったか(区分の見積もりの上限の前提。単価が足りないと費用を 0 と数えて上限をすり抜ける)。
     * 止め方 = 設計/AI/実行と費用/接続先と費用.md 5.4。会話の型の種類は用途ごとに確かめる({@see ConversationSettings::problems()})。
     *
     * @param  AnyKindSettings  $kind  呼び手が引いた種類の設定
     * @return array{list<string>, bool}
     */
    private function pricingProblems(string $code, array $kind): array
    {
        if ($kind['type'] === KindSettings::CONVERSATION) {
            return [[], true];
        }

        [$provider, $model] = [$kind['provider'], $kind['model']];
        [$enough, $short] = match ($kind['type']) {
            KindSettings::PROMPT => [
                $this->settings->pricesEveryInput($provider, $model),
                "種類 {$code} の接続先とモデルの単価が無いか、最後の段に入力の上限がある(config/ai_runs.php の pricing)",
            ],
            KindSettings::TRANSCRIPTION => [
                $this->settings->pricesPerAudioMinute($provider, $model),
                "種類 {$code} の接続先とモデルの音声の分単価が無い(config/ai_runs.php の pricing)",
            ],
        };

        $problems = [...$this->settings->priceProblems($provider, $model), ...($enough === false ? [$short] : [])];

        return [$problems, $enough === true];
    }

    /**
     * 区分ごとの食い違い(守る条件 = 実行と費用/接続先と費用.md 5.2)。区分の表が配列でなければ、区分の確かめはどれも止める。
     *
     * @param  AllKindSettings  $all
     * @param  array<string, bool>  $priced  区分 → 属する種類の単価の確かめがどれも通ったか
     * @return list<string>
     */
    private function budgetProblems(array $all, array $priced): array
    {
        $problems = [];

        foreach ($this->settings->budgetTableFits() ? $this->settings->budgetNames() : [] as $budget) {
            if (! RunBudget::hasMessages($budget)) {
                $problems[] = "予算の区分 {$budget} に超えたときの文が無い(RunBudget)";
            }

            $exceeded = $this->ceilingReadsFit($budget, $all, $priced) ? $this->exceededLimit($budget, $all) : null;

            if ($exceeded !== null) {
                [$label, $limit] = $exceeded;
                $problems[] = "予算の区分 {$budget} の守る条件を破る: 1 依頼の見積もりの上限の最大が{$label}の上限 {$limit} ドル以上";
            }
        }

        return $problems;
    }

    /**
     * 区分の見積もりの上限の確かめが走るか(形の合わない値や通らない前提で数えると、上限を小さく数えて守る条件をすり抜ける)。
     * 読む値と前提 = 設計/AI/実行と費用/接続先と費用.md 5.4。
     *
     * @param  AllKindSettings  $all
     * @param  array<string, bool>  $priced
     */
    private function ceilingReadsFit(string $budget, array $all, array $priced): bool
    {
        [$kinds, , $unfitting] = $all;
        $prompted = array_any(
            $kinds,
            fn (array $kind): bool => $kind['budget'] === $budget && $kind['type'] === KindSettings::PROMPT,
        );

        return $this->settings->budgetLimitsFit($budget)
            && ($priced[$budget] ?? true)
            && ! in_array(null, $unfitting, true)
            && ! in_array($budget, $unfitting, true)
            && (! $prompted || $this->settings->estimateFits());
    }

    /**
     * 文字起こしの種類の食い違い(超えたときの文が時間で数え、区切りは無音へ寄せる幅を残すため)。確かめの単位と前提 = 接続先と費用.md 5.4。
     *
     * @param  TranscriptionKindSettings  $kind
     * @return list<string>
     */
    private function transcriptionProblems(string $code, array $kind): array
    {
        $hour = $kind['max_audio_seconds'] % self::SECONDS_PER_HOUR === 0
            ? []
            : ["種類 {$code} の 1 本の長さの上限 {$kind['max_audio_seconds']} 秒が 3600 秒の倍数でない(config/ai_runs.php の kinds)"];
        $chunk = match (true) {
            ! $this->transcription->positiveIntsFit([TranscriptionSettings::SILENCE_WINDOW]) => null,
            $kind['chunk_seconds'] <= $this->transcription->silenceWindowSeconds() => [
                "種類 {$code} の区切りの長さ {$kind['chunk_seconds']} 秒が無音を探す幅以下(config/ai_runs.php)",
            ],
            default => [],
        };
        $timed = $chunk === [] && $this->settings->retryAfterFits()
            && $this->transcription->positiveIntsFit([TranscriptionSettings::CONVERSION_TIMEOUT]);
        $jobTime = $timed ? $this->jobTimeProblems($code, $this->transcription->jobTimeout($kind)) : [];

        return [...$hour, ...($chunk ?? []), ...$jobTime];
    }

    /**
     * 1 依頼の見積もりの上限の最大が、区分の日・月・累計の上限のうち空でない最も小さいもの以上なら、その期間の名前と上限(その日の最初の依頼でも
     * 断られる)。3 つとも空なら確かめない(null)。同じ額の期間が 2 つ以上あれば、短い期間(日 → 月 → 累計)の名前を出す。
     *
     * @param  AllKindSettings  $all
     * @return array{string, string}|null
     */
    private function exceededLimit(string $budget, array $all): ?array
    {
        $smallest = null;
        $smallestMicros = null;

        foreach (AiSettings::BUDGET_PERIODS as $period => $label) {
            $limit = $this->settings->budget($budget, $period);

            if ($limit === null) {
                continue;
            }

            $usd = RunEstimates::usd($limit);
            $micros = RunEstimates::micros($usd);

            if ($smallestMicros === null || $micros < $smallestMicros) {
                $smallest = [$label, $usd];
                $smallestMicros = $micros;
            }
        }

        $worst = RunEstimates::micros($this->estimates->ceilingAmong($budget, $all));

        return $smallestMicros !== null && $worst >= $smallestMicros ? $smallest : null;
    }
}
