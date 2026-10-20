<?php

namespace App\Queries\Alpha;

use App\Models\Alpha\AlphaItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * 読み取りは副作用に数えない(取得・件数・存在の確認・ファイルの読み取り)
 */
final class ReadsOnly
{
    /**
     * @return Collection<int, AlphaItem>
     */
    public function items(): Collection
    {
        return AlphaItem::query()->where('id', 1)->get();
    }

    public function count(): int
    {
        return DB::table('alpha_items')->count();
    }

    public function size(string $path): int|false
    {
        return (new \SplFileInfo($path))->getSize();
    }

    public function exists(): bool
    {
        return Schema::hasTable('alpha_items') && Storage::exists('report.txt');
    }
}
