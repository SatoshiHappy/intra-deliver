<?php

namespace App\Actions\Alpha;

use App\Jobs\Alpha\AlphaJob;

/**
 * 業務処理 → ジョブ(投入)
 */
final class DispatchesJob
{
    public function usesAlphaJob(AlphaJob $alphaJob): AlphaJob
    {
        return $alphaJob;
    }
}
