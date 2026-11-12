<?php

namespace App\Actions\Ai\Tools;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordViewAccess;
use Closure;
use DateTimeInterface;

/**
 * 許した口のクラスの、主体の判定をしない操作(題の読み取り・申請の経路を含めない閲覧の判定)を呼ぶのは禁止(呼び出しの形によらない)
 */
final class CallsTitleOf
{
    public function __construct(private RecordDisplayQuery $display, private RecordViewAccess $access) {}

    public function title(int $recordId): ?string
    {
        return $this->display->titleOf($recordId);
    }

    /** @return array{RecordDisplayQuery, string} */
    public function titleCallable(): array
    {
        return [$this->display, 'titleOf'];
    }

    public function titleClosure(): Closure
    {
        return $this->display->titleOf(...);
    }

    public function titleStatically(int $recordId): ?string
    {
        return RecordDisplayQuery::titleOf($recordId);
    }

    public function titleString(): string
    {
        return RecordDisplayQuery::class.'::titleOf';
    }

    public function viewInModule(Requester $requester, int $recordId, DateTimeInterface $date): bool
    {
        return $this->access->canViewInModule($requester, 'tasks', $recordId, $date);
    }

    public function suppressedTitle(int $recordId): ?string
    {
        // @phpstan-ignore allowedOperation
        return $this->display->titleOf($recordId);
    }
}
