<?php

namespace App\Http\Controllers\Web\Alpha;

use App\Providers\FixtureProvider;

/**
 * 入口 → 起動は禁止
 */
final class ReachesProviders
{
    public function usesFixtureProvider(FixtureProvider $fixtureProvider): FixtureProvider
    {
        return $fixtureProvider;
    }
}
