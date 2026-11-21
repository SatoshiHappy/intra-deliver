<?php

namespace App\Queries\Ai\Api;

use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunEstimates;
use App\Queries\Ai\RunSpending;
use DateTimeInterface;
use LogicException;

/**
 * AI の予算の区分の状態の読み取り(口の保証の正本 = docs の 設計/AI/公開の口.md 3 節の表の「予算の区分の状態」の行、
 * 種類の型ごとの比べ方 = 設計/AI/実行と費用/接続先と費用.md 5.2 の比べ方の表)。
 * 呼び手の画面が、受けられない依頼を送る前に止めるのに使う。主体の判定をしない(上限の状態は利用者によらない)。
 */
class RunBudgetQuery
{
    /** 超えた期間の値 */
    public const DAY = 'day';

    public const MONTH = 'month';

    public const TOTAL = 'total';

    public function __construct(
        private RunSpending $spending,
        private RunEstimates $estimates,
        private KindSettings $kinds,
    ) {}

    /**
     * 区分の状態 = 区分に属する種類の型ごとの比べのどれかが当たる期間(比べ方 = docs の 設計/AI/実行と費用/接続先と費用.md 5.2 の表。
     * 複数なら長い方)。
     *
     * @param  DateTimeInterface  $at  日と月の境を決める時刻(Asia/Tokyo の暦で切る)
     * @return self::DAY|self::MONTH|self::TOTAL|null
     *
     * @throws LogicException 設定に無い区分のとき・形の合わない種類が区分に属しうるとき
     */
    public function exceededPeriod(string $budget, DateTimeInterface $at): ?string
    {
        $ceiling = $this->estimates->ceilingOf($budget);
        $types = array_column(array_filter(
            $this->kinds->all()[0],
            fn (array $kind): bool => $kind['budget'] === $budget,
        ), 'type');
        $text = $types !== [] && array_diff($types, [KindSettings::CONVERSATION]) === []
            ? null
            : $this->spending->exceededPeriod($budget, $at, $ceiling);
        $conversation = in_array(KindSettings::CONVERSATION, $types, true)
            ? $this->spending->reachedPeriod($budget, $at)
            : null;

        return self::longer($text, $conversation);
    }

    /**
     * 種類の区分の状態(区分は種類の設定から引く。呼び手は区分の名前を持たない)。その種類の型の比べだけで見る(文章・文字起こしは
     * その種類の 1 依頼の見積もりの上限、会話の型は使った額 ≥ 上限。後で続けて出す依頼の種類が決まっている呼び手のため)。返すものは
     * {@see self::exceededPeriod()} と同じ。
     *
     * @param  string  $kindCode  種類のコード(文章・文字起こし・会話)
     * @return self::DAY|self::MONTH|self::TOTAL|null
     *
     * @throws LogicException 設定に無い種類・設定の形が合わないとき
     */
    public function exceededPeriodOfKind(string $kindCode, DateTimeInterface $at): ?string
    {
        $kind = $this->kinds->settings($kindCode)
            ?? throw new LogicException("AI の種類 {$kindCode} が設定(config/ai_runs.php の kinds)に無い");

        return $kind['type'] === KindSettings::CONVERSATION
            ? $this->spending->reachedPeriod($kind['budget'], $at)
            : $this->spending->exceededPeriod($kind['budget'], $at, $this->estimates->ceilingOfKind($kind));
    }

    /**
     * 2 つの期間の長い方(累計 → 月 → 日)。
     *
     * @param  self::DAY|self::MONTH|self::TOTAL|null  $left
     * @param  self::DAY|self::MONTH|self::TOTAL|null  $right
     * @return self::DAY|self::MONTH|self::TOTAL|null
     */
    private static function longer(?string $left, ?string $right): ?string
    {
        foreach ([self::TOTAL, self::MONTH, self::DAY] as $period) {
            if ($left === $period || $right === $period) {
                return $period;
            }
        }

        return null;
    }
}
