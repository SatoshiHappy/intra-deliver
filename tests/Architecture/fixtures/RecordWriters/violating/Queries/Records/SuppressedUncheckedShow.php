<?php

namespace App\Queries\Records;

use App\Queries\Records\Api\RecordShowUnchecked;

/**
 * 抑制の注釈を付けても消せない(nonIgnorable)。入れ物の内部も判定しない読み取りの呼び手の一覧の外
 */
final class SuppressedUncheckedShow
{
    // @phpstan-ignore phpat.uncheckedShowDependencies
    public function show(RecordShowUnchecked $show): RecordShowUnchecked
    {
        return $show;
    }
}
