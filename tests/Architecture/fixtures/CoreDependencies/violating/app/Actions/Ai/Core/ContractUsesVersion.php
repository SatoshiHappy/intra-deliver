<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Core\Ported\PortedHelper;
use Laravel\Ai\Gateway\StepResponse;

/**
 * 約束が版の名前空間と、版だけが依れる 1 ステップの口の値の型に依存するのは禁止
 */
final class ContractUsesVersion
{
    public function __construct(
        public PortedHelper $helper,
        public StepResponse $response,
    ) {}
}
