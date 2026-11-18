<?php

namespace App\Actions\Ai\Core;

/**
 * ループの終わり方(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の終わり方の表)。種類ごとの作り手で作り、種類に当たらない欄は持たない。
 */
final readonly class LoopEnding
{
    /**
     * @param  list<string>  $askingCallIds  確認待ちの問うの呼び出しの id
     */
    private function __construct(
        public LoopEndingKind $kind,
        public array $askingCallIds = [],
        public ?LoopStop $stop = null,
        public ?string $stopReason = null,
        public ?LoopFailure $failure = null,
    ) {}

    /** 成功 */
    public static function succeeded(): self
    {
        return new self(LoopEndingKind::Succeeded);
    }

    /**
     * 確認待ち。
     *
     * @param  non-empty-list<string>  $callIds  問うの呼び出しの id
     */
    public static function awaitingConfirmation(array $callIds): self
    {
        return new self(LoopEndingKind::AwaitingConfirmation, askingCallIds: $callIds);
    }

    /** 止めた($reason = 受け手が返した理由。中核が当てた長さと回数では null) */
    public static function stopped(LoopStop $stop, ?string $reason = null): self
    {
        return new self(LoopEndingKind::Stopped, stop: $stop, stopReason: $reason);
    }

    /** 失敗 */
    public static function failed(LoopFailure $failure): self
    {
        return new self(LoopEndingKind::Failed, failure: $failure);
    }
}
