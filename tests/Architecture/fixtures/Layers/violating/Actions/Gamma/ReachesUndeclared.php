<?php

namespace App\Actions\Gamma;

use App\Actions\Beta\BetaAction;

/**
 * モジュール Gamma → 依存先に宣言していない Beta は禁止
 */
final class ReachesUndeclared
{
    public function usesBetaAction(BetaAction $betaAction): BetaAction
    {
        return $betaAction;
    }
}
