<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\TranscribedSegment;

/**
 * 区切りの 1 つの文字起こしの応答(内部。laravel/ai の型を {@see TranscriptionClient} の外に出さない)。時刻は区切りの始まりからの秒。
 */
final readonly class ChunkTranscript
{
    /**
     * @param  list<TranscribedSegment>  $segments  区間(接続先の順)
     * @param  int  $inputTokens  入力のトークン(laravel/ai の TranscriptionUsage)
     * @param  int  $outputTokens  出力のトークン
     */
    public function __construct(
        public array $segments,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
