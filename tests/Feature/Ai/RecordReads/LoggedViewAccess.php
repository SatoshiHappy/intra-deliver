<?php

namespace Tests\Feature\Ai\RecordReads;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordViewAccess;
use DateTimeInterface;

/** 呼び出しを記録する閲覧の判定(テストの中だけ。判定は元の口のまま) */
final class LoggedViewAccess extends RecordViewAccess
{
    public RecordReadLog $log;

    public function canView(Requester $requester, string $moduleCode, int $recordId, DateTimeInterface $date): bool
    {
        $this->log->enter('RecordViewAccess::canView');

        return parent::canView($requester, $moduleCode, $recordId, $date);
    }
}
