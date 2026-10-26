<?php

namespace App\Actions\Records;

use App\Actions\Records\Api\UpdateRecordUnchecked;

/**
 * 抑制の注釈を付けても消せない(nonIgnorable)。入れ物の内部も判定しない更新の呼び手の一覧の外
 */
final class SuppressedUncheckedUpdate
{
    // @phpstan-ignore phpat.uncheckedUpdateDependencies
    public function updateRecord(UpdateRecordUnchecked $updateRecord): UpdateRecordUnchecked
    {
        return $updateRecord;
    }
}
