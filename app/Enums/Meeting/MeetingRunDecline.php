<?php

namespace App\Enums\Meeting;

/**
 * 文字起こしの完了の中の議事録の依頼が断られた理由(表 meeting_runs の declined_code。docs の 設計/業務ツール/会議/README.md の 2 節・
 * 処理.md の 3.8)。値は DB の検査制約と同じ一覧で、AI の依頼の口が断った理由のコード(App\Actions\Ai\Api\RunRequestRejected の定数)と同じ文字列。
 */
enum MeetingRunDecline: string
{
    /** 予算の区分の上限を超える */
    case Budget = 'budget';

    /** 送る文が入力の上限の文字数を超える */
    case TooLong = 'too_long';
}
