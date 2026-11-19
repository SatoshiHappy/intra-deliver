<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use Laravel\Ai\Gateway\StepResponse;

/**
 * 1 回の試しの結び(応答・失敗・読む途中で止めた、のどれか 1 つと、その試しの読み)。
 */
final readonly class SubstituteAttempt
{
    private function __construct(
        public SubstituteReading $reading,
        public ?StepResponse $response = null,
        public ?SubstituteFailure $failure = null,
    ) {}

    public static function responded(SubstituteReading $reading, StepResponse $response): self
    {
        return new self($reading, $response);
    }

    public static function failed(SubstituteReading $reading, SubstituteFailure $failure): self
    {
        return new self($reading, failure: $failure);
    }

    public static function stopped(SubstituteReading $reading): self
    {
        return new self($reading);
    }
}
