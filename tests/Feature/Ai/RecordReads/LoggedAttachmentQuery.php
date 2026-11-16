<?php

namespace Tests\Feature\Ai\RecordReads;

use App\Queries\Records\Api\RecordAttachmentQuery;

/** 呼び出しを記録する添付の読み取り(テストの中だけ。読み取りは元の口のまま) */
final class LoggedAttachmentQuery extends RecordAttachmentQuery
{
    public RecordReadLog $log;

    public function forRecord(int $recordId, int $limit, ?int $beforeId = null): array
    {
        $this->log->enter('RecordAttachmentQuery::forRecord');

        return parent::forRecord($recordId, $limit, $beforeId);
    }
}
