<?php

namespace App\Enums\Meeting;

/**
 * 会議の通知の種類(表 notices の type の「モジュール.種類」。docs の 設計/業務ツール/会議/通知と削除予定日.md の 6.1)。
 * 文は画面が種類と params(title・失敗は reason)から作る(6.4)。
 */
enum MeetingNoticeType: string
{
    /** 議事録の完了(作成の議事録を書いた) */
    case MinutesCreated = 'meeting.minutes_created';

    /** 議事録の再作成の完了 */
    case MinutesRegenerated = 'meeting.minutes_regenerated';

    /** 文字起こしの失敗 */
    case TranscriptionFailed = 'meeting.transcription_failed';

    /** 議事録の失敗(実行の失敗・文字起こしの後の議事録の依頼が断られた) */
    case MinutesFailed = 'meeting.minutes_failed';
}
