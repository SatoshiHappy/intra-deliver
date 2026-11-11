<?php

namespace App\Actions\Alpha;

use App\Models\Alpha\AlphaItem;

/**
 * クラスを置くディレクトリのファイルは配線のファイルでないので、永続化に依存できる
 */
final class UsesModel
{
    public function name(AlphaItem $item): string
    {
        return $item::class;
    }
}
