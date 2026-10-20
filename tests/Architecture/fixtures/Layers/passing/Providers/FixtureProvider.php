<?php

namespace App\Providers;

use App\Actions\Beta\UsesAllowed;
use App\Http\Controllers\Web\Alpha\AlphaController;
use App\Models\Alpha\AlphaItem;
use App\Support\SupportValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * 起動 → App\ のすべての層・外部
 */
final class FixtureProvider extends ServiceProvider
{
    public function usesAlphaController(AlphaController $alphaController): AlphaController
    {
        return $alphaController;
    }

    public function usesUsesAllowed(UsesAllowed $usesAllowed): UsesAllowed
    {
        return $usesAllowed;
    }

    public function usesAlphaItem(AlphaItem $alphaItem): AlphaItem
    {
        return $alphaItem;
    }

    public function usesSupportValue(SupportValue $supportValue): SupportValue
    {
        return $supportValue;
    }

    public function usesDb(DB $db): DB
    {
        return $db;
    }
}
