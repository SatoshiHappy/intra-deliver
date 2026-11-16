<?php

namespace App\Actions\Ai\Tools;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use DateTimeInterface;

/**
 * 一覧から外した操作(参照制限の今の値を持たない詳細の値の読み取り)を呼ぶのは禁止(呼び出しの形によらない)
 */
final class CallsShow
{
    public function __construct(private RecordDisplayQuery $display) {}

    /** @return array<string, mixed> */
    public function shown(Requester $requester, int $recordId, DateTimeInterface $date): array
    {
        return $this->display->show($requester, $recordId, $date, 'ja');
    }

    /** @return array{RecordDisplayQuery, string} */
    public function shownCallable(): array
    {
        return [$this->display, 'show'];
    }
}
