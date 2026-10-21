<?php

namespace App\Actions\Ai;

use App\Queries\Ai\AiSettings;
use App\Queries\Ai\RunEstimates;

/**
 * 種類と予算の区分の設定の食い違い(起動のテストが落とす = docs の 設計/AI/データと失敗と検査.md 8 節、実行と費用.md 4.1・5.2)。
 */
class KindLimitChecks
{
    public function __construct(private AiSettings $settings, private RunEstimates $estimates) {}

    /**
     * 種類ごとの食い違い。ジョブの時間が retry_after 以上だと、走っている間に同じ仕事が 2 回目に配られて費用が 2 回かかる。
     *
     * @return list<string>
     */
    public function kindProblems(string $code): array
    {
        $kind = $this->settings->kind($code);

        if ($kind === null) {
            return [];
        }

        $problems = [];
        $jobTimeout = AiSettings::jobTimeout($kind['timeout_seconds']);
        $retryAfter = $this->settings->queueRetryAfter();

        if (! in_array($kind['budget'], $this->settings->budgetNames(), true)) {
            $problems[] = "種類 {$code} の予算の区分 {$kind['budget']} が設定(config/ai_runs.php の budget)に無い";
        }

        if ($jobTimeout >= $retryAfter) {
            $problems[] = "種類 {$code} のジョブの時間 {$jobTimeout} 秒がキューの retry_after {$retryAfter} 秒以上(config/queue.php)";
        }

        return $problems;
    }

    /**
     * 区分ごとの食い違い(守る条件 = 実行と費用.md 5.2)。見積もりの上限は単価が要るので、単価の食い違いが無いときだけ呼ぶ。
     *
     * @return list<string>
     */
    public function budgetProblems(): array
    {
        $problems = [];

        foreach ($this->settings->budgetNames() as $budget) {
            if (! RunBudget::hasMessages($budget)) {
                $problems[] = "予算の区分 {$budget} に超えたときの文が無い(RunBudget)";
            }

            if ($this->exceedsDailyLimit($budget)) {
                $limit = RunEstimates::usd((float) $this->settings->budget($budget, 'daily_usd'));
                $problems[] = "予算の区分 {$budget} の守る条件を破る: 1 依頼の見積もりの上限の最大が日の上限 {$limit} ドル以上";
            }
        }

        return $problems;
    }

    /** 1 依頼の見積もりの上限の最大が日の上限以上か(その日の最初の依頼でも断られる。日の上限が無い区分は守る必要が無い) */
    private function exceedsDailyLimit(string $budget): bool
    {
        $daily = $this->settings->budget($budget, 'daily_usd');
        $worst = RunEstimates::micros($this->estimates->ceilingOf($budget));

        return $daily !== null && $worst >= RunEstimates::micros(RunEstimates::usd($daily));
    }
}
