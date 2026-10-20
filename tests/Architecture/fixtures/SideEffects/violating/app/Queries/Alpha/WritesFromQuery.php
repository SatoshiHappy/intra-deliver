<?php

namespace App\Queries\Alpha;

use App\Models\Alpha\AlphaItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 読み取りから DB への書き込み(インスタンス・ビルダ・クエリビルダ・スキーマ)
 */
final class WritesFromQuery
{
    public function save(AlphaItem $item): void
    {
        $item->save();
    }

    public function update(): int
    {
        return AlphaItem::query()->where('id', 1)->update(['name' => 'x']);
    }

    public function insert(): bool
    {
        return DB::table('alpha_items')->insert(['name' => 'x']);
    }

    public function drop(): void
    {
        Schema::dropIfExists('alpha_items');
    }
}
