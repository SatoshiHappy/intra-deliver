<?php

namespace App\Http\Middleware;

use App\Actions\Alpha\AlphaAction;

/**
 * 入口(共通)→ 業務処理(モジュール)は禁止
 */
final class ReachesActions
{
    public function usesAlphaAction(AlphaAction $alphaAction): AlphaAction
    {
        return $alphaAction;
    }
}
