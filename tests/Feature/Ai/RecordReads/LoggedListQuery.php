<?php

namespace Tests\Feature\Ai\RecordReads;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordListQuery;
use DateTimeInterface;

/** 呼び出しを記録する一覧の読み取り(テストの中だけ。読み取りは元の口のまま) */
final class LoggedListQuery extends RecordListQuery
{
    public RecordReadLog $log;

    public function list(
        Requester $requester,
        string $moduleCode,
        array $conditions,
        ?string $sort,
        int $page,
        int $perPage,
        ?array $fieldCodes,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $this->log->enter('RecordListQuery::list');

        return parent::list(
            $requester,
            $moduleCode,
            $conditions,
            $sort,
            $page,
            $perPage,
            $fieldCodes,
            $date,
            $localeCode,
        );
    }
}
