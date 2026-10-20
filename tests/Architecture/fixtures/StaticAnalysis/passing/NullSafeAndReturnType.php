<?php

namespace Fixture\StaticAnalysis;

use DateTimeImmutable;

final class NullSafeAndReturnType
{
    public function year(?DateTimeImmutable $date): ?string
    {
        return $date?->format('Y');
    }

    public function count(): int
    {
        return 3;
    }
}
