<?php

namespace Fixture\StaticAnalysis;

use DateTimeImmutable;

final class NullableAndReturnType
{
    public function year(?DateTimeImmutable $date): string
    {
        return $date->format('Y');
    }

    public function count(): int
    {
        return 'three';
    }
}
