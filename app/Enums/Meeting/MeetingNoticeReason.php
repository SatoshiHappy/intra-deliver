<?php

namespace App\Enums\Meeting;

/**
 * 会議の失敗の通知の理由(params の reason。docs の 設計/業務ツール/会議/通知と削除予定日.md の 6.4 の理由の文を選ぶ値)。
 * 実行の失敗の種類と細かい理由(設計/AI/データと失敗と検査.md 7 節)、断られた対応の理由を、画面の文の分け方に写した値。
 */
enum MeetingNoticeReason: string
{
    /** AI の利用の上限に達した(断られた対応の理由) */
    case Budget = 'budget';

    /** 長すぎるため処理できなかった(断られた対応の理由) */
    case TooLong = 'too_long';

    /** 議事録の出力か、欄・件名が長すぎた(TooLong は断られた対応の理由で別) */
    case Truncated = 'truncated';

    /** 音声から書き起こせなかった */
    case AudioUnusable = 'audio_unusable';

    /** 入れ物の検証が会議の更新を断った */
    case RecordUnavailable = 'record_unavailable';

    /** 上のどれでもない失敗 */
    case Other = 'other';
}
