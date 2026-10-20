<?php

namespace App\Support;

use App\Support\SupportValue;
use Illuminate\Support\Str;
use Stringable;

/**
 * 共有 → 外部(DB を除く)・PHP 標準
 */
final class UsesExternal
{
    public function usesSupportValue(SupportValue $supportValue): SupportValue
    {
        return $supportValue;
    }

    public function usesStr(Str $str): Str
    {
        return $str;
    }

    public function usesStringable(Stringable $stringable): Stringable
    {
        return $stringable;
    }
}
