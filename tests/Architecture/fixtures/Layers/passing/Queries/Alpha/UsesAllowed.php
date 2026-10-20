<?php

namespace App\Queries\Alpha;

use App\Enums\Alpha\AlphaStatus;
use App\Models\Alpha\AlphaItem;
use App\Support\SupportValue;
use Illuminate\Database\ConnectionInterface;

/**
 * 読み取り → 永続化・列挙・共有・外部(DB を含む)
 */
final class UsesAllowed
{
    public function usesAlphaItem(AlphaItem $alphaItem): AlphaItem
    {
        return $alphaItem;
    }

    public function usesAlphaStatus(AlphaStatus $alphaStatus): AlphaStatus
    {
        return $alphaStatus;
    }

    public function usesSupportValue(SupportValue $supportValue): SupportValue
    {
        return $supportValue;
    }

    public function usesConnectionInterface(ConnectionInterface $connectionInterface): ConnectionInterface
    {
        return $connectionInterface;
    }
}
