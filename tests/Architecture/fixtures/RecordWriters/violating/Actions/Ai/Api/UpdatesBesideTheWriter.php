<?php

namespace App\Actions\Ai\Api;

use App\Actions\Records\Api\UpdateRecordUnchecked;

/**
 * 書き込みの口と同じ名前空間のほかのクラスが判定しない更新に依存する(一覧の class はそのクラスだけ)
 */
final class UpdatesBesideTheWriter
{
    public function __construct(private UpdateRecordUnchecked $updateRecordUnchecked) {}

    public function updateRecordUnchecked(): UpdateRecordUnchecked
    {
        return $this->updateRecordUnchecked;
    }
}
