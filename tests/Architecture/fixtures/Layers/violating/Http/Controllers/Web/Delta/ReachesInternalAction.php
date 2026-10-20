<?php

namespace App\Http\Controllers\Web\Delta;

use App\Actions\Beta\BetaAction;

/**
 * モジュール Delta の入口 → 依存先 Beta の業務処理の内部(Api の下でない)は禁止
 */
final class ReachesInternalAction
{
    public function usesBetaAction(BetaAction $betaAction): BetaAction
    {
        return $betaAction;
    }
}
