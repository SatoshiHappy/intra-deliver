<?php

namespace App\Enums\Meeting;

/**
 * 会議と AI の実行の対応の段(表 meeting_runs の stage。docs の 設計/業務ツール/会議/README.md の 2 節・処理.md の 3.7)。
 * 値は DB の検査制約と同じ一覧。
 */
enum MeetingRunStage: string
{
    /** 文字起こし */
    case Transcription = 'transcription';

    /** 議事録 */
    case Minutes = 'minutes';
}
