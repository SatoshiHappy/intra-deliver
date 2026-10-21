<?php

namespace App\Enums\Meeting;

/**
 * 会議と AI の実行の対応の契機(表 meeting_runs の origin。docs の 設計/業務ツール/会議/README.md の 2 節・処理.md の 3.7)。
 * 値は DB の検査制約と同じ一覧。
 */
enum MeetingRunOrigin: string
{
    /** 音声から作成 */
    case Create = 'create';

    /** 再作成(AI は件名を書かない) */
    case Regenerate = 'regenerate';
}
