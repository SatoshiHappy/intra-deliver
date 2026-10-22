<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\RunRequestDeclined;
use App\Queries\Ai\Api\RunBudgetQuery;
use App\Queries\Ai\RunSpending;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 費用の上限の確認(規則の正本 = docs の 設計/AI/実行と費用.md 5.2)。呼び手が勧告ロック({@see self::lock()})を取ったトランザクションの中で呼び、
 * 確認と行の追加を直列にする。超えた期間が複数なら長い期間の文を出す(短い期間の「明日お試しください」は、長い期間が尽きていると当たらないため)。
 * 使った額の数え方は {@see RunSpending}(区分の状態の読み取りと同じ)。
 */
class RunBudget
{
    /** 誤りのキー(画面の欄に依らない AI の依頼そのものの誤り) */
    public const ERROR_KEY = 'ai';

    public const DAILY_EXCEEDED = '今日の AI の利用の上限に達しました。手で入力するか、明日お試しください';

    public const MONTHLY_EXCEEDED = '今月の AI の利用の上限に達しました。手で入力してください';

    public const TOTAL_EXCEEDED = 'AI の利用の上限に達しました。手で入力してください';

    public const TRANSCRIPTION_DAILY_EXCEEDED = '今日の音声の取り込みの上限に達しました。';

    public const TRANSCRIPTION_MONTHLY_EXCEEDED = '今月の音声の取り込みの上限に達しました。';

    /** 配備の設定で累計の上限を置いたときの文(公開デモは累計の上限なし) */
    public const TRANSCRIPTION_TOTAL_EXCEEDED = '音声の取り込みの上限に達しました。';

    /** 区分と超えた期間ごとの 422 の文(予算の区分の名前は config/ai_runs.php の budget の鍵) */
    private const MESSAGES = [
        'default' => [
            RunBudgetQuery::DAY => self::DAILY_EXCEEDED,
            RunBudgetQuery::MONTH => self::MONTHLY_EXCEEDED,
            RunBudgetQuery::TOTAL => self::TOTAL_EXCEEDED,
        ],
        'transcription' => [
            RunBudgetQuery::DAY => self::TRANSCRIPTION_DAILY_EXCEEDED,
            RunBudgetQuery::MONTH => self::TRANSCRIPTION_MONTHLY_EXCEEDED,
            RunBudgetQuery::TOTAL => self::TRANSCRIPTION_TOTAL_EXCEEDED,
        ],
    ];

    /** AI の費用の勧告ロックの鍵(共通マスタ 4,100,000,001・業務モジュール 4,200,000,000,000,000〜 と重ならない値) */
    private const LOCK_KEY = 4_300_000_000_000_000;

    public function __construct(private RunSpending $spending) {}

    /**
     * AI の費用の鍵を排他で取る(トランザクションの終わりで外れる。呼び手がトランザクションを持つ)。鍵は予算の区分によらず 1 つ。
     */
    public function lock(): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
    }

    /**
     * 超えたときの文を持つ区分か(設定の区分が文を持たないと、超えたときに 422 でなく作りの誤りになるため、起動の時に確かめる)。
     */
    public static function hasMessages(string $budget): bool
    {
        return isset(self::MESSAGES[$budget]);
    }

    /**
     * @param  string  $budget  予算の区分
     * @param  string  $reservedUsd  この依頼の見積もり(小数 6 桁)
     *
     * @throws RunRequestDeclined 上限を超えるとき(422。キー ai)
     * @throws LogicException 設定に無い区分のとき
     */
    public function ensureWithin(string $budget, string $reservedUsd): void
    {
        $period = $this->spending->exceededPeriod($budget, CarbonImmutable::now(), $reservedUsd);

        if ($period !== null) {
            $message = self::MESSAGES[$budget][$period] ?? throw new LogicException("予算の区分 {$budget} の上限を超えたときの文が無い");

            throw new RunRequestDeclined(RunRequestDeclined::BUDGET, $message);
        }
    }
}
