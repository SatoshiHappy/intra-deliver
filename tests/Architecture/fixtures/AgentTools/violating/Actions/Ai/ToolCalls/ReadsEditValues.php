<?php

namespace App\Actions\Ai\ToolCalls;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use DateTimeInterface;

/**
 * 判定の関数の名前空間が、RecordFormQuery の allows・definition のほか(レコードの値を返す判定しない操作)を呼ぶのは禁止
 */
final class ReadsEditValues
{
    public function __construct(private RecordFormQuery $forms) {}

    /** @return array<string, mixed> */
    public function values(Requester $requester, int $recordId, DateTimeInterface $date): array
    {
        return $this->forms->editValues($requester, 'tasks', $recordId, $date, 'ja');
    }
}
