<?php

namespace App\Jobs\Delta;

use App\Queries\Beta\BetaQuery;

/**
 * モジュール Delta のジョブ → 依存先 Beta の読み取りの内部(Api の下でない)は禁止(ジョブの置き場も公開の口の規則の対象)
 */
final class ReachesInternals
{
    public function usesBetaQuery(BetaQuery $betaQuery): BetaQuery
    {
        return $betaQuery;
    }
}
