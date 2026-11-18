<?php

namespace App\Actions\Ai\Core;

/**
 * 確かめの口の返り値(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「確かめの口」)。続けるは位置ごとの作り手で作り、止めるは区分と
 * 受け手の理由を持つ。位置に合うかは {@see CheckPoint::accepts()}。
 */
final readonly class CheckReply
{
    private function __construct(
        public ?StopKind $stop,
        public ?string $reason = null,
        public ?int $stepId = null,
        public ?int $waitLimitMs = null,
    ) {}

    /** 送る前の位置で続ける($stepId = 繋ぎが作ったステップの行の番号) */
    public static function proceedToSend(int $stepId): self
    {
        return new self(null, stepId: $stepId);
    }

    /** 送り直しの待ちの前で続ける($waitLimitMs = 待ちの上限。無ければ null) */
    public static function proceedToWait(?int $waitLimitMs): self
    {
        return new self(null, waitLimitMs: $waitLimitMs);
    }

    /** ほかの位置で続ける */
    public static function proceed(): self
    {
        return new self(null);
    }

    /** 止める */
    public static function stop(StopKind $kind, ?string $reason = null): self
    {
        return new self($kind, $reason);
    }

    /** 止めるか */
    public function stops(): bool
    {
        return $this->stop !== null;
    }
}
