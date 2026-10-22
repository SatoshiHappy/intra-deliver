<?php

namespace App\Queries\Ai\Api;

use App\Queries\Ai\KindSettings;
use App\Queries\Ai\RunEstimates;
use App\Queries\Ai\RunSpending;
use DateTimeInterface;
use LogicException;

/**
 * AI の予算の区分の状態の読み取り(口の保証の正本 = docs の 設計/AI/公開の口.md 3 節の表の「予算の区分の状態」の行)。
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
     * 区分の「使った額 + その区分に属する種類(文章・文字起こし)の見積もりの上限の最大」が上限を超える期間。複数なら長い方。無ければ null。
     * 見積もりの上限は依頼の口の確かめと同じ計算(短い依頼なら受けられる場合も超えたと返す = 多めに止まる側)。
     *
     * @param  DateTimeInterface  $at  日と月の境を決める時刻(Asia/Tokyo の暦で切る)
     * @return self::DAY|self::MONTH|self::TOTAL|null
     *
     * @throws LogicException 設定に無い区分のとき
     */
    public function exceededPeriod(string $budget, DateTimeInterface $at): ?string
    {
        return $this->spending->exceededPeriod($budget, $at, $this->estimates->ceilingOf($budget));
    }

    /**
     * 種類の区分の状態(区分は種類の設定から引く。呼び手は区分の名前を持たない)。返すもの・判定は {@see self::exceededPeriod()} と同じ。
     *
     * @param  string  $kindCode  種類のコード(文章・文字起こし)
     * @return self::DAY|self::MONTH|self::TOTAL|null
     *
     * @throws LogicException 設定に無い種類・設定の形が合わないとき
     */
    public function exceededPeriodOfKind(string $kindCode, DateTimeInterface $at): ?string
    {
        $kind = $this->kinds->prompt($kindCode) ?? $this->kinds->transcription($kindCode)
            ?? throw new LogicException("AI の種類 {$kindCode} が設定(config/ai_runs.php の kinds)に無い");

        return $this->exceededPeriod($kind['budget'], $at);
    }
}
