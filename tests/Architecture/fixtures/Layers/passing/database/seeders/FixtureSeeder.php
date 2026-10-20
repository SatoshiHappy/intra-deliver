<?php

namespace Database\Seeders\LayerFixture;

use App\Actions\Alpha\AlphaAction;
use App\Enums\Alpha\AlphaStatus;
use App\Models\Alpha\AlphaItem;
use App\Support\SupportValue;
use Illuminate\Database\Seeder;

/**
 * database/seeders → 業務処理・永続化・列挙・共有・外部
 */
final class FixtureSeeder extends Seeder
{
    public function usesAlphaAction(AlphaAction $alphaAction): AlphaAction
    {
        return $alphaAction;
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
}
