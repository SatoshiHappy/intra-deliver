<?php

namespace App\Actions\Records;

use App\Actions\Records\Api\HideRecordModuleUnchecked;

/**
 * 抑制の注釈を付けても消せない(nonIgnorable)。入れ物の内部も非表示の口の呼び手の一覧の外
 */
final class SuppressedHide
{
    // @phpstan-ignore phpat.hideModuleDependencies
    public function hideModule(HideRecordModuleUnchecked $hideModule): HideRecordModuleUnchecked
    {
        return $hideModule;
    }
}
