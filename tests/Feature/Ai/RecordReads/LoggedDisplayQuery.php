<?php

namespace Tests\Feature\Ai\RecordReads;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use DateTimeInterface;

/** 呼び出しを記録するレコードの表示の口(テストの中だけ。読み取りは元の口のまま) */
final class LoggedDisplayQuery extends RecordDisplayQuery
{
    public RecordReadLog $log;

    public function detail(Requester $requester, int $recordId, DateTimeInterface $date, string $localeCode): array
    {
        $this->log->enter('RecordDisplayQuery::detail');

        return parent::detail($requester, $recordId, $date, $localeCode);
    }
}
