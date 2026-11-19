<?php

namespace App\Queries\Ai;

use App\Queries\Ai\Api\RunBudgetQuery;

/**
 * 予算の区分と超えた期間ごとの文(docs の 設計/AI/実行と費用/接続先と費用.md 5.2)。依頼の口の 422 と、会話の型の止めた(費用の上限)の
 * 実行の読み取りの文(設計/AI/データと失敗と検査.md 7 節)が同じ文を使う。予算の区分の名前は config/ai_runs.php の budget の鍵。
 */
final class BudgetMessages
{
    public const DAILY_EXCEEDED = '今日の AI の利用の上限に達しました。手で入力するか、明日お試しください';

    public const MONTHLY_EXCEEDED = '今月の AI の利用の上限に達しました。手で入力してください';

    public const TOTAL_EXCEEDED = 'AI の利用の上限に達しました。手で入力してください';

    public const TRANSCRIPTION_DAILY_EXCEEDED = '今日の音声の取り込みの上限に達しました。';

    public const TRANSCRIPTION_MONTHLY_EXCEEDED = '今月の音声の取り込みの上限に達しました。';

    /** 配備の設定で累計の上限を置いたときの文(公開デモは累計の上限なし) */
    public const TRANSCRIPTION_TOTAL_EXCEEDED = '音声の取り込みの上限に達しました。';

    /** 区分と超えた期間ごとの文 */
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

    /** 超えたときの文を持つ区分か */
    public static function has(string $budget): bool
    {
        return isset(self::MESSAGES[$budget]);
    }

    /** 区分と期間の文(無ければ null) */
    public static function of(string $budget, string $period): ?string
    {
        return self::MESSAGES[$budget][$period] ?? null;
    }
}
