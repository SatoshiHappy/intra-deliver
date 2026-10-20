<?php

namespace UpdateCheck;

/**
 * スクリプト全体の時間の予算(ジョブの timeout-minutes から結果を書く分を残した 25 分)。
 * コマンドと HTTP の要求の上限は、それぞれの上限と予算の残りの小さいほう。残りが無ければ新しく始めない。
 */
final readonly class TimeBudget
{
    public const DEFAULT_SECONDS = 1500;

    public function __construct(private float $deadline) {}

    public static function starting(int $seconds = self::DEFAULT_SECONDS): self
    {
        return new self(microtime(true) + $seconds);
    }

    public function remaining(): float
    {
        return $this->deadline - microtime(true);
    }

    /**
     * 上限 $seconds と予算の残りの小さいほう(秒、1 以上)。
     *
     * @throws BudgetExceededException 残りが無い
     */
    public function limit(int $seconds): int
    {
        $remaining = $this->remaining();

        if ($remaining <= 0) {
            throw new BudgetExceededException;
        }

        return max(1, (int) min($seconds, ceil($remaining)));
    }
}
