<?php

namespace App\Actions\Beta;

use App\Actions\Beta\BetaAction;
use App\Enums\Alpha\AlphaStatus;
use App\Models\Alpha\AlphaItem;
use App\Queries\Alpha\AlphaQuery;
use App\Support\SupportValue;
use Illuminate\Support\Facades\DB;

/**
 * 業務処理(Beta)→ 業務処理・読み取り・永続化・列挙・共有・外部(DB を含む)。Beta は宣言した依存先 Alpha に依存できる
 */
final class UsesAllowed
{
    public function usesBetaAction(BetaAction $betaAction): BetaAction
    {
        return $betaAction;
    }

    public function usesAlphaQuery(AlphaQuery $alphaQuery): AlphaQuery
    {
        return $alphaQuery;
    }

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

    public function usesDB(DB $dB): DB
    {
        return $dB;
    }
}
