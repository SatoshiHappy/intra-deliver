<?php

namespace App\Actions\Ai\Core;

/**
 * 1 回の送信を確かめで送らなかった(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の口の表。止める区分と受け手の理由)。
 */
final readonly class SendNotSent implements SendResult
{
    public function __construct(
        public StopKind $kind,
        public ?string $reason,
    ) {}
}
