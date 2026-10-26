<?php

namespace App\Actions\Ai\Api;

use App\Actions\Records\Api\UpdateRecordUnchecked;

/**
 * 読む口(判定しない読み取りの一覧の class)も、判定しない更新の一覧の外
 */
final class ReadRecordForRun
{
    public function __construct(private UpdateRecordUnchecked $updateRecordUnchecked) {}

    public function updateRecordUnchecked(): UpdateRecordUnchecked
    {
        return $this->updateRecordUnchecked;
    }
}
