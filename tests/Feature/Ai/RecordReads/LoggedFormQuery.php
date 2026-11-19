<?php

namespace Tests\Feature\Ai\RecordReads;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use DateTimeInterface;

/** 編集の初期値と画面の初期値の読み取りの呼び出しを記録する口(テストの中だけ。読み取りは元の口のまま) */
final class LoggedFormQuery extends RecordFormQuery
{
    public RecordReadLog $log;

    public function editValues(
        Requester $requester,
        string $moduleCode,
        int $recordId,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $this->log->enter('RecordFormQuery::editValues');

        return parent::editValues($requester, $moduleCode, $recordId, $date, $localeCode);
    }

    public function draftValues(
        Requester $requester,
        string $moduleCode,
        array $values,
        array $details,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $this->log->enter('RecordFormQuery::draftValues');

        return parent::draftValues($requester, $moduleCode, $values, $details, $date, $localeCode);
    }
}
