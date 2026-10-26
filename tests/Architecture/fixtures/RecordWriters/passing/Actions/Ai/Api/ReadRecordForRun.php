<?php

namespace App\Actions\Ai\Api;

use App\Queries\Records\Api\RecordShowUnchecked;

/**
 * 判定しない読み取りの一覧の class(AI の読む口。そのクラスだけが通る)
 */
final class ReadRecordForRun
{
    public function __construct(private RecordShowUnchecked $recordShowUnchecked) {}

    public function recordShowUnchecked(): RecordShowUnchecked
    {
        return $this->recordShowUnchecked;
    }
}
