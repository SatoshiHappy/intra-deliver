<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\TranscribedSegment;

/**
 * 音声 1 本の文字起こしの結果(内部。{@see TranscribeAudio} が返し、{@see ExecuteTranscription} が完了に使う)。
 */
final readonly class TranscriptionOutcome
{
    /**
     * @param  list<TranscribedSegment>  $segments  区間(時刻をずらし、区切りが 2 つ以上ならラベルに区切りの番号を添えた後)
     * @param  int  $chunkCount  区切りの数
     * @param  RunResult  $result  使用量・費用・原文
     */
    public function __construct(
        public array $segments,
        public int $chunkCount,
        public RunResult $result,
    ) {}
}
