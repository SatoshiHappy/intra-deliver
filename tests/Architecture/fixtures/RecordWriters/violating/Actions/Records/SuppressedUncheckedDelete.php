<?php

namespace App\Actions\Records;

use App\Actions\Records\Api\DeleteRecordUnchecked;

/**
 * 抑制の注釈を付けても消せない(nonIgnorable)。入れ物の内部も判定しない削除の呼び手の一覧の外
 */
final class SuppressedUncheckedDelete
{
    // @phpstan-ignore phpat.uncheckedDeleteDependencies
    public function deleteRecord(DeleteRecordUnchecked $deleteRecord): DeleteRecordUnchecked
    {
        return $deleteRecord;
    }
}
