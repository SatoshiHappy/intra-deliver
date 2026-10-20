<?php

namespace App\Actions\Beta;

use App\Actions\Alpha\SendReport;

/**
 * 台帳②でキューへの投入を許していないモジュール(Beta)の業務処理からジョブの投入
 */
final class DispatchesJob
{
    public function report(): void
    {
        SendReport::dispatch();
    }
}
