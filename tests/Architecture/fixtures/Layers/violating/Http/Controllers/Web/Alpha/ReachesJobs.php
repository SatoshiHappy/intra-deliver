<?php

namespace App\Http\Controllers\Web\Alpha;

use App\Jobs\Alpha\AlphaJob;

/**
 * 入口 → ジョブは禁止(投入できるのは業務処理だけ)
 */
final class ReachesJobs
{
    public function usesAlphaJob(AlphaJob $alphaJob): AlphaJob
    {
        return $alphaJob;
    }
}
