<?php

namespace Tests\Unit\Actions\Ai\Core\Support;

use App\Actions\Ai\Core\CoreClock;
use DateTimeImmutable;

/**
 * 中核のテストの時計の口(待つと時刻が進む。待った長さを控える)。
 */
final class FakeClock implements CoreClock
{
    /** @var list<int> */
    public array $waits = [];

    public function __construct(private DateTimeImmutable $now = new DateTimeImmutable('2026-11-18T12:00:00+09:00')) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function wait(int $milliseconds): void
    {
        $this->waits[] = $milliseconds;
        $this->now = $this->now->modify("+{$milliseconds} milliseconds");
    }
}
