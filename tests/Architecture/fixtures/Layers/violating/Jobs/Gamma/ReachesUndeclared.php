<?php

namespace App\Jobs\Gamma;

use App\Actions\Beta\BetaAction;

/**
 * モジュール Gamma のジョブ → 依存先に宣言していない Beta は禁止(ジョブの置き場もモジュールの規則の対象)
 */
final class ReachesUndeclared
{
    public function usesBetaAction(BetaAction $betaAction): BetaAction
    {
        return $betaAction;
    }
}
