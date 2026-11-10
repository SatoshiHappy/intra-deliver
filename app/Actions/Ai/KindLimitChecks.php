<?php

namespace App\Actions\Ai;

use App\Queries\Ai\AiSettings;
use App\Queries\Ai\ConversationSettings;
use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunEstimates;
use App\Queries\Ai\TranscriptionSettings;
use LogicException;

/**
 * 種類と予算の区分の設定の食い違い(`ai:check-settings` とテストが落とす = docs の 設計/AI/データと失敗と検査.md 8 節、実行と費用/README.md 4.1・5 節・5.2)。
 * 種類の設定の形(型ごとの必須の鍵)は {@see KindSettings::problems()} が見て、形の合う種類だけをここで見る。
 *
 * @phpstan-import-type TranscriptionKindSettings from KindSettings
 * @phpstan-import-type AnyKindSettings from KindSettings
 */
class KindLimitChecks
{
    /** 文字起こしの 1 本の長さの上限の刻み(超えたときの文が「<N> 時間まで」なので時間の倍数) */
    private const SECONDS_PER_HOUR = 3600;

    /** 予算の区分の期間(config/ai_runs.php の budget の鍵 → 文の名前。短い順) */
    private const BUDGET_PERIODS = ['daily_usd' => '日', 'monthly_usd' => '月', 'total_usd' => '累計'];

    public function __construct(
        private AiSettings $settings,
        private TranscriptionSettings $transcription,
        private RunEstimates $estimates,
    ) {}

    /**
     * 種類ごとの食い違い(どの型も、予算の区分が設定にある)。ジョブの時間が retry_after 以上だと、走っている間に同じ仕事が 2 回目に配られて
     * 費用が 2 回かかる(文章と文字起こし。会話の型のジョブの時間は会話のジョブを足す版で確かめる)。
     *
     * @param  AnyKindSettings  $kind  呼び手が引いた種類の設定
     * @return list<string>
     */
    public function kindProblems(string $code, array $kind): array
    {
        $problems = in_array($kind['budget'], $this->settings->budgetNames(), true)
            ? []
            : ["種類 {$code} の予算の区分 {$kind['budget']} が設定(config/ai_runs.php の budget)に無い"];
        [$jobTimeout, $typeProblems] = match ($kind['type']) {
            KindSettings::PROMPT => [AiSettings::jobTimeout($kind['timeout_seconds']), []],
            KindSettings::TRANSCRIPTION => $this->transcriptionProblems($code, $kind),
            KindSettings::CONVERSATION => [null, []],
        };
        $problems = [...$problems, ...$typeProblems];
        $retryAfter = $this->settings->queueRetryAfter();

        return $jobTimeout !== null && $jobTimeout >= $retryAfter
            ? [...$problems, "種類 {$code} のジョブの時間 {$jobTimeout} 秒がキューの retry_after {$retryAfter} 秒以上(config/queue.php)"]
            : $problems;
    }

    /**
     * 単価の食い違い。文章の種類は最後の段に入力の上限があると、どの段にも収まらない入力が残る。文字起こしの種類は分単価が要る
     * (費用を 0 と数えて上限をすり抜けないため = 設計/AI/実行と費用/接続先と費用.md 5 節)。会話の型の種類は用途ごとに確かめる
     * ({@see ConversationSettings::problems()})。段の規則の 1 か所 = {@see AiSettings::pricesEveryInput()}。
     *
     * @param  AnyKindSettings  $kind  呼び手が引いた種類の設定
     * @return list<string>
     */
    public function pricingProblems(string $code, array $kind): array
    {
        return match ($kind['type']) {
            KindSettings::PROMPT => $this->settings->pricesEveryInput($kind['provider'], $kind['model'])
                ? []
                : ["種類 {$code} の接続先とモデルの単価が無いか、最後の段に入力の上限がある(config/ai_runs.php の pricing)"],
            KindSettings::TRANSCRIPTION => $this->settings->audioMinutePrice($kind['provider'], $kind['model']) === null
                ? ["種類 {$code} の接続先とモデルの音声の分単価が無い(config/ai_runs.php の pricing)"]
                : [],
            KindSettings::CONVERSATION => [],
        };
    }

    /**
     * 区分ごとの食い違い(守る条件 = 実行と費用/接続先と費用.md 5.2)。見積もりの上限は単価が要るので、単価の食い違いが無いときだけ呼ぶ。
     * 期間の上限(日・月・累計)はどれも数として読む(数でない値は作りの誤りとして起動で止める = 実行と費用/接続先と費用.md 5 節。読むのは {@see self::exceededLimit()})。
     *
     * @param  array<string, AnyKindSettings>  $kinds  呼び手が引いた全部の種類の設定(コード → 設定)
     * @return list<string>
     *
     * @throws LogicException 期間の上限が数でないとき
     */
    public function budgetProblems(array $kinds): array
    {
        $problems = [];

        foreach ($this->settings->budgetNames() as $budget) {
            if (! RunBudget::hasMessages($budget)) {
                $problems[] = "予算の区分 {$budget} に超えたときの文が無い(RunBudget)";
            }

            $exceeded = $this->exceededLimit($budget, $kinds);

            if ($exceeded !== null) {
                [$label, $limit] = $exceeded;
                $problems[] = "予算の区分 {$budget} の守る条件を破る: 1 依頼の見積もりの上限の最大が{$label}の上限 {$limit} ドル以上";
            }
        }

        return $problems;
    }

    /**
     * 文字起こしの種類の食い違いとジョブの時間(区切りの長さが無音を探す幅以下ならジョブの時間を決められないので null)。
     *
     * @param  TranscriptionKindSettings  $kind
     * @return array{int|null, list<string>}
     */
    private function transcriptionProblems(string $code, array $kind): array
    {
        $problems = $kind['max_audio_seconds'] % self::SECONDS_PER_HOUR === 0
            ? []
            : ["種類 {$code} の 1 本の長さの上限 {$kind['max_audio_seconds']} 秒が 3600 秒の倍数でない(config/ai_runs.php の kinds)"];

        if ($kind['chunk_seconds'] <= $this->transcription->silenceWindowSeconds()) {
            return [null, [...$problems, "種類 {$code} の区切りの長さ {$kind['chunk_seconds']} 秒が無音を探す幅以下(config/ai_runs.php)"]];
        }

        return [$this->transcription->jobTimeout($kind), $problems];
    }

    /**
     * 1 依頼の見積もりの上限の最大が、区分の日・月・累計の上限のうち空でない最も小さいもの以上なら、その期間の名前と上限(その日の最初の依頼でも
     * 断られる)。3 つとも空なら確かめない(null)。同じ額の期間が 2 つ以上あれば、短い期間(日 → 月 → 累計)の名前を出す。
     *
     * @param  array<string, AnyKindSettings>  $kinds
     * @return array{string, string}|null
     */
    private function exceededLimit(string $budget, array $kinds): ?array
    {
        $smallest = null;
        $smallestMicros = null;

        foreach (self::BUDGET_PERIODS as $period => $label) {
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

        $worst = RunEstimates::micros($this->estimates->ceilingAmong($budget, $kinds));

        return $smallestMicros !== null && $worst >= $smallestMicros ? $smallest : null;
    }
}
