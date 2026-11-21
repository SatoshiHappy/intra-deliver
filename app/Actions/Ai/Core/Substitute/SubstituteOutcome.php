<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\SendFailure;
use App\Actions\Ai\Core\StopKind;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteReading;
use Laravel\Ai\Gateway\StepResponse;

/**
 * 1 回の送信の結び(応答・送らなかったか止めた・失敗のどれか)。
 */
final readonly class SubstituteOutcome
{
    private function __construct(
        public ?StepResponse $response = null,
        public ?SubstituteReading $reading = null,
        public ?StopKind $stop = null,
        public ?string $stopReason = null,
        public ?SendFailure $failure = null,
        public ?int $actualTokens = null,
        public ?int $limitTokens = null,
        public bool $sent = false,
        public ?int $stepId = null,
    ) {}

    /** 応答を受けた($stepId = 応答の属するステップの行。要約の境目が指す = docs の 設計/AI/会話の中核.md 13.2) */
    public static function responded(StepResponse $response, SubstituteReading $reading, int $stepId): self
    {
        return new self($response, $reading, sent: true, stepId: $stepId);
    }

    /** 送る前の確かめで送らなかった */
    public static function notSent(StopKind $stop, ?string $reason): self
    {
        return new self(stop: $stop, stopReason: $reason);
    }

    /** 応答を読む途中で止めた */
    public static function stopped(?string $reason, SubstituteReading $reading): self
    {
        return new self(reading: $reading, stop: StopKind::User, stopReason: $reason, sent: true);
    }

    public static function failed(SendFailure $failure, ?int $actual, ?int $limit, SubstituteReading $reading): self
    {
        return new self(reading: $reading, failure: $failure, actualTokens: $actual, limitTokens: $limit, sent: true);
    }
}
