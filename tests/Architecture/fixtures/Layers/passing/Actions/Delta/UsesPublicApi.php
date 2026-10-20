<?php

namespace App\Actions\Delta;

use App\Actions\Beta\Api\BetaCommand;
use App\Queries\Alpha\AlphaQuery;
use App\Queries\Beta\Api\BetaReader;

/**
 * モジュール Delta → Beta の公開の口(Api の下)と、移行中の一覧に載った Alpha の内部
 */
final class UsesPublicApi
{
    public function usesBetaCommand(BetaCommand $betaCommand): BetaCommand
    {
        return $betaCommand;
    }

    public function usesBetaReader(BetaReader $betaReader): BetaReader
    {
        return $betaReader;
    }

    public function usesAlphaQuery(AlphaQuery $alphaQuery): AlphaQuery
    {
        return $alphaQuery;
    }
}
