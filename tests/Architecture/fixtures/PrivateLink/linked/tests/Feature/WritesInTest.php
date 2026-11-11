<?php

namespace Tests\Feature;

use App\Models\Alpha\AlphaItem;

/**
 * 照合しないディレクトリ(テスト)の書き込み
 */
final class WritesInTest
{
    public function save(AlphaItem $item): void
    {
        $item->save();
    }
}
