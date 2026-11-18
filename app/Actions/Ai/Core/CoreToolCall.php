<?php

namespace App\Actions\Ai\Core;

/**
 * 道具を動かす口に渡す呼び出し 1 つ(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「道具を動かす口」)。$callId = 会話の中で
 * 呼び出しを指す id(SDK の `id`。空なら中核が振った UUID)。
 */
final readonly class CoreToolCall
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public string $callId,
        public string $name,
        public array $arguments,
    ) {}
}
