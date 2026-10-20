<?php

namespace App\Queries\Alpha;

use App\Jobs\Alpha\AlphaJob;

/**
 * 読み取り → ジョブは禁止(投入できるのは業務処理だけ)
 */
final class ReachesJobs
{
    public function usesAlphaJob(AlphaJob $alphaJob): AlphaJob
    {
        return $alphaJob;
    }
}
