<?php

namespace App\Actions\Ai\Api;

use App\Actions\Records\Api\UpdateRecordUnchecked;

/**
 * 判定しない更新の一覧の class(AI の書き込みの口。そのクラスだけが通る)
 */
final class WriteRecordFromRun
{
    public function __construct(private UpdateRecordUnchecked $updateUnchecked) {}

    public function updateUnchecked(): UpdateRecordUnchecked
    {
        return $this->updateUnchecked;
    }
}
