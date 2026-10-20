<?php

namespace App\Models\Alpha;

use App\Enums\Alpha\AlphaStatus;
use App\Support\SupportValue;
use Illuminate\Database\Eloquent\Model;

/**
 * 永続化 → 列挙・共有・外部(DB を含む)
 */
final class AlphaItem extends Model
{
    public function usesAlphaStatus(AlphaStatus $alphaStatus): AlphaStatus
    {
        return $alphaStatus;
    }

    public function usesSupportValue(SupportValue $supportValue): SupportValue
    {
        return $supportValue;
    }
}
