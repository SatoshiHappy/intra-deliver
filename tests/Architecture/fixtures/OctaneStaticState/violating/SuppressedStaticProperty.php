<?php

namespace App\Support\OctaneFixture;

/**
 * 抑制注釈を付けても Octane 安全 (b) は消せない(nonIgnorable)。
 */
final class SuppressedStaticProperty
{
    // @phpstan-ignore staticState.property
    public static int $calls = 0;
}
