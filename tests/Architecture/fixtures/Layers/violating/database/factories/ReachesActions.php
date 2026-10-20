<?php

namespace Database\Factories\LayerFixture;

use App\Actions\Alpha\AlphaAction;

/**
 * database/factories → 業務処理は禁止
 */
final class ReachesActions
{
    public function usesAlphaAction(AlphaAction $alphaAction): AlphaAction
    {
        return $alphaAction;
    }
}
