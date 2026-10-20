<?php

namespace App\Http\Controllers\Web\Alpha;

use App\Actions\Alpha\AlphaAction;
use App\Http\Controllers\Controller;
use App\Enums\Alpha\AlphaStatus;
use App\Http\Middleware\HandleCommon;
use App\Http\Requests\Alpha\AlphaRequest;
use App\Queries\Alpha\AlphaQuery;
use App\Support\SupportValue;
use DateTimeImmutable;
use Illuminate\Http\Request;

/**
 * 入口 → 業務処理・読み取り・入口・入口(共通)・列挙・共有・外部(DB を除く)・PHP 標準
 */
final class AlphaController extends Controller
{
    public function usesAlphaAction(AlphaAction $alphaAction): AlphaAction
    {
        return $alphaAction;
    }

    public function usesAlphaQuery(AlphaQuery $alphaQuery): AlphaQuery
    {
        return $alphaQuery;
    }

    public function usesAlphaRequest(AlphaRequest $alphaRequest): AlphaRequest
    {
        return $alphaRequest;
    }

    public function usesHandleCommon(HandleCommon $handleCommon): HandleCommon
    {
        return $handleCommon;
    }

    public function usesAlphaStatus(AlphaStatus $alphaStatus): AlphaStatus
    {
        return $alphaStatus;
    }

    public function usesSupportValue(SupportValue $supportValue): SupportValue
    {
        return $supportValue;
    }

    public function usesRequest(Request $request): Request
    {
        return $request;
    }

    public function usesDateTimeImmutable(DateTimeImmutable $dateTimeImmutable): DateTimeImmutable
    {
        return $dateTimeImmutable;
    }
}
