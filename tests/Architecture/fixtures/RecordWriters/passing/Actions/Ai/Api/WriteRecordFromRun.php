<?php

namespace App\Actions\Ai\Api;

use App\Actions\Records\Api\UpdateRecord;

/**
 * 一覧の class(AI の書き込みの口。そのクラスだけが通る)
 */
final class WriteRecordFromRun
{
    public function __construct(private UpdateRecord $updateRecord) {}

    public function updateRecord(): UpdateRecord
    {
        return $this->updateRecord;
    }
}
