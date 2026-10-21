<?php

namespace App\Enums\Meeting;

/**
 * 書き起こしの出どころ(表 transcripts の source。docs の 設計/業務ツール/会議/README.md の 2 節)。値は DB の検査制約と同じ一覧。
 */
enum TranscriptSource: string
{
    /** 音声(文字起こしの実行で作る) */
    case Audio = 'audio';

    /** テキストのファイル(.txt・.vtt・.md) */
    case TextFile = 'text_file';

    /** 貼り付け(ファイルの名前を持たない) */
    case Paste = 'paste';
}
