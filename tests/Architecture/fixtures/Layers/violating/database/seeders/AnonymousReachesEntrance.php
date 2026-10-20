<?php

namespace Database\Seeders\LayerFixture;

use App\Http\Requests\Alpha\AlphaRequest;

/**
 * seeder のメソッドの中の匿名クラス → 入口も禁止
 */
final class AnonymousReachesEntrance
{
    public function helper(): object
    {
        return new class
        {
            public function usesAlphaRequest(AlphaRequest $alphaRequest): AlphaRequest
            {
                return $alphaRequest;
            }
        };
    }
}
