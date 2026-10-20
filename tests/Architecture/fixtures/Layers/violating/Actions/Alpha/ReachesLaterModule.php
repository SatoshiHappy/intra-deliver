<?php

namespace App\Actions\Alpha;

use App\Queries\Beta\BetaQuery;

/**
 * モジュール Alpha → 宣言していない Beta(一覧で後ろ)は禁止
 */
final class ReachesLaterModule
{
    public function usesBetaQuery(BetaQuery $betaQuery): BetaQuery
    {
        return $betaQuery;
    }
}
