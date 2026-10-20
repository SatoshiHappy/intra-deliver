<?php

namespace App\Http\Middleware;

use App\Support\SupportValue;
use Illuminate\Http\Request;

/**
 * 入口(共通)→ 共有・外部(DB を除く)
 */
final class HandleCommon
{
    public function usesSupportValue(SupportValue $supportValue): SupportValue
    {
        return $supportValue;
    }

    public function usesRequest(Request $request): Request
    {
        return $request;
    }
}
