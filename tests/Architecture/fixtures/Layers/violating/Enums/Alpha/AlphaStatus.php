<?php

namespace App\Enums\Alpha;

use App\Support\SupportValue;

/**
 * 列挙 → 共有
 */
enum AlphaStatus: string
{
    case Open = 'open';

    public function usesSupportValue(SupportValue $supportValue): SupportValue
    {
        return $supportValue;
    }
}
