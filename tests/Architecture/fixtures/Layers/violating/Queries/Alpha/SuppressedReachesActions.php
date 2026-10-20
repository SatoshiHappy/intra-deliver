<?php

namespace App\Queries\Alpha;

use App\Actions\Alpha\AlphaAction;

/**
 * 抑制の注釈を付けても層の規則は消せない(nonIgnorable)。依存は引数と戻り値の型の 1 行だけ
 */
final class SuppressedReachesActions
{
    // @phpstan-ignore phpat.queriesDependencies
    public function usesAlphaAction(AlphaAction $alphaAction): AlphaAction
    {
        return $alphaAction;
    }
}
