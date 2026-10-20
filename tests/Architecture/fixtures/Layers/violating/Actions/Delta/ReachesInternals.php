<?php

namespace App\Actions\Delta;

use App\Queries\Beta\BetaQuery;

/**
 * モジュール Delta → 依存先 Beta の読み取りの内部(Api の下でない)は禁止
 */
final class ReachesInternals
{
    public function usesBetaQuery(BetaQuery $betaQuery): BetaQuery
    {
        return $betaQuery;
    }
}
