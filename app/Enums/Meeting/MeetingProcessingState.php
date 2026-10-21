<?php

namespace App\Enums\Meeting;

/**
 * 会議の処理の状態(会議の最後の対応とその実行の状態から読む。docs の 設計/業務ツール/会議/処理.md の 3.8)。状態の無い会議(手で作った・完了)は値を持たない。
 */
enum MeetingProcessingState: string
{
    /** 実行が待ちか処理中 */
    case Processing = 'processing';

    /** 断られた対応か、実行が失敗 */
    case Failed = 'failed';
}
