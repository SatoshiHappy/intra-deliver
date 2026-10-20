<?php

namespace Database\Seeders\LayerFixture;

use App\Http\Requests\Alpha\AlphaRequest;

/**
 * database/seeders → 入口は禁止
 */
final class ReachesEntrance
{
    public function usesAlphaRequest(AlphaRequest $alphaRequest): AlphaRequest
    {
        return $alphaRequest;
    }
}
