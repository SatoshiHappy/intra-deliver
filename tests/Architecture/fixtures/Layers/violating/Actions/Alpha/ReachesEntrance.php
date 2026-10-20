<?php

namespace App\Actions\Alpha;

use App\Http\Requests\Alpha\AlphaRequest;

/**
 * 業務処理 → 入口は禁止
 */
final class ReachesEntrance
{
    public function usesAlphaRequest(AlphaRequest $alphaRequest): AlphaRequest
    {
        return $alphaRequest;
    }
}
