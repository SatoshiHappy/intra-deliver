<?php

namespace Tests\Feature\Ai\RecordReads;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordActivityQuery;
use DateTimeInterface;

/** 呼び出しを記録するコメントと更新履歴の口(テストの中だけ。読み取りは元の口のまま) */
final class LoggedActivityQuery extends RecordActivityQuery
{
    public RecordReadLog $log;

    public function comments(int $recordId, int $page, int $perPage, DateTimeInterface $date, string $localeCode): array
    {
        $this->log->enter('RecordActivityQuery::comments');

        return parent::comments($recordId, $page, $perPage, $date, $localeCode);
    }

    public function revisions(
        Requester $requester,
        int $recordId,
        int $page,
        int $perPage,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $this->log->enter('RecordActivityQuery::revisions');

        return parent::revisions($requester, $recordId, $page, $perPage, $date, $localeCode);
    }
}
