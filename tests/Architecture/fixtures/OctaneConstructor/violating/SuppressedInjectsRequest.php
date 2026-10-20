<?php

namespace App\Support\OctaneFixture;

use Illuminate\Http\Request;

/**
 * 抑制注釈を付けても Octane 安全 (a) は消せない(nonIgnorable)。
 */
final class SuppressedInjectsRequest
{
    public function __construct(
        // @phpstan-ignore constructor.forbiddenParameterType
        public readonly Request $request,
    ) {}
}
