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
    ) {}

    /** 送る前の位置で続ける($stepId = 繋ぎが作ったステップの行の番号) */
    public static function proceedToSend(int $stepId): self
    {
        return new self(null, stepId: $stepId);
    }

    /** ほかの位置で続ける(送り直しの待ちの前も。待ちは実行の時間の残りによらず待つ = docs の 設計/AI/会話の中核.md 13.5) */
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
