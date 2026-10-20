<?php

namespace Database\Seeders\SideEffectFixture;

use App\Models\Alpha\AlphaItem;

/**
 * DB の初期化は照合しない
 */
final class FixtureSeeder
{
    public function run(): void
    {
        AlphaItem::create(['name' => 'x']);
    }
}
