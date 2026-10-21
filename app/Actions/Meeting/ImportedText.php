<?php

namespace App\Actions\Meeting;

use App\Enums\Meeting\TranscriptSource;

/**
 * 取り込んだテキストを行に分けた結果(書き起こしの出どころ・ファイルの名前・発言の行)。
 */
final readonly class ImportedText
{
    /**
     * @param  string|null  $fileName  取り込んだファイルの名前(255 字で切ったもの)。貼り付けは null
     * @param  list<TranscriptLine>  $lines  発言の行(1 行以上)
     */
    public function __construct(
        public TranscriptSource $source,
        public ?string $fileName,
        public array $lines,
    ) {}
}
