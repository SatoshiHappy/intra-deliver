<?php

namespace App\Actions\Ai\ToolCalls;

use App\Queries\Records\Api\RecordListQuery;

/**
 * 判定の関数の名前空間が、10.4 の (2) に無い Records の口(一覧の読み取り)に依存するのは禁止
 */
final class DependsOnList
{
    public function __construct(
        private RecordListQuery $lists,
    ) {}

    public function lists(): RecordListQuery
    {
        return $this->lists;
    }
}
