<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\StopKind;

/**
 * 送る前の確かめの関数の結果(種類 = {@see SendCheckResult})。種類ごとの値は作り手が決め、ほかの組は作れない。
 */
final readonly class SendCheckOutcome
{
    /**
     * @param  string|null  $period  費用の上限で止めたときの超えた期間(複数なら長い方 = 設計/AI/実行と費用/接続先と費用.md 5.2)
     */
    private function __construct(
        public SendCheckResult $result,
        public ?int $stepId = null,
        public ?StopKind $stop = null,
        public ?string $period = null,
    ) {}

    public static function send(int $stepId): self
    {
        return new self(SendCheckResult::Send, stepId: $stepId);
    }

    public static function stop(StopKind $stop, ?string $period = null): self
    {
        return new self(SendCheckResult::Stop, stop: $stop, period: $period);
    }

    public static function notSent(): self
    {
        return new self(SendCheckResult::NotSent);
    }

    public static function ended(): self
    {
        return new self(SendCheckResult::Ended);
    }
}
