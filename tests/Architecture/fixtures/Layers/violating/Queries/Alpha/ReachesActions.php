<?php

namespace App\Queries\Alpha;

use App\Actions\Alpha\AlphaAction;

/**
 * 読み取り → 業務処理は禁止
 */
final class ReachesActions
{
    public function usesAlphaAction(AlphaAction $alphaAction): AlphaAction
    {
        return $alphaAction;
    }
}
