<?php

namespace App\Actions\Meeting;

use App\Queries\Records\Api\RecordShowUnchecked;

/**
 * 業務ツールのモジュール(会議の完了の受け手など)が読む口を迂回して判定しない読み取りに依存するのは禁止
 */
final class ShowsUnchecked
{
    public function __construct(private RecordShowUnchecked $recordShowUnchecked) {}

    public function recordShowUnchecked(): RecordShowUnchecked
    {
        return $this->recordShowUnchecked;
    }
}
