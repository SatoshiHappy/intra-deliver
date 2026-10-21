<?php

namespace App\Actions\Meeting;

/**
 * 書き起こしの発言の行 1 つ(表 transcript_segments の 1 行の値。docs の 設計/業務ツール/会議/README.md の 2 節)。
 * 書く前と、材料を組むときに同じ形で扱う。
 */
final readonly class TranscriptLine
{
    /**
     * @param  string  $text  発言(前後の空白を除いた空でない文字列)
     * @param  string|null  $speakerLabel  話者のラベル(100 字まで)。無ければ null
     * @param  string|null  $startedAtSec  会議の中の開始の秒(小数 3 桁までの 10 進の文字列)。無ければ null
     * @param  string|null  $endedAtSec  終了の秒(同じ形)。無ければ null
     */
    public function __construct(
        public string $text,
        public ?string $speakerLabel = null,
        public ?string $startedAtSec = null,
        public ?string $endedAtSec = null,
    ) {}
}
