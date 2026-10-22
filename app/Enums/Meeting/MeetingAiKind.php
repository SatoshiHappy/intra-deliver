<?php

namespace App\Enums\Meeting;

/**
 * 会議の AI の実行の種類のコード(設定 config/ai_runs.php の kinds のコード。docs の 設計/業務ツール/会議/文字起こしと議事録.md の 4.1・5.1)。
 * 種類の実装と、区分の状態を種類で読む読み取りが同じ値を使う。
 */
enum MeetingAiKind: string
{
    /** 会議の議事録(文章) */
    case Minutes = 'meeting.minutes';

    /** 会議の文字起こし */
    case Transcription = 'meeting.transcription';
}
