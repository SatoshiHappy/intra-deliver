<?php

namespace App\Actions\Ai\Api;

use App\Actions\Records\Api\UpdateRecord;
use App\Queries\Records\Api\RecordShowUnchecked;

/**
 * 書き込みの口(判定しない更新の一覧の class)も、判定する更新の一覧(ADR 0029 で外した)と判定しない読み取りの一覧の外
 */
final class WriteRecordFromRun
{
    public function __construct(private UpdateRecord $updateRecord, private RecordShowUnchecked $recordShowUnchecked) {}

    public function updateRecord(): UpdateRecord
    {
        return $this->updateRecord;
    }

    public function recordShowUnchecked(): RecordShowUnchecked
    {
        return $this->recordShowUnchecked;
    }
}
