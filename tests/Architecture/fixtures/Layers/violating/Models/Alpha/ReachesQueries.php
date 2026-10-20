<?php

namespace App\Models\Alpha;

use App\Queries\Alpha\AlphaQuery;

/**
 * 永続化 → 読み取りは禁止
 */
final class ReachesQueries
{
    public function usesAlphaQuery(AlphaQuery $alphaQuery): AlphaQuery
    {
        return $alphaQuery;
    }
}
