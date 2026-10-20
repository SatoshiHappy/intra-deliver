<?php

namespace Database\Seeders\ModuleTablesFixture;

use App\Models\Alpha\AlphaItem;
use Illuminate\Database\Seeder;

/**
 * 照合しないディレクトリ(DB の初期化)
 */
final class SeedsAlpha extends Seeder
{
    public function run(): void
    {
        AlphaItem::create(['name' => 'x']);
    }
}
