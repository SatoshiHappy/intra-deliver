<?php

namespace Tests\Feature\Ai;

use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use Closure;
use DateTimeInterface;

/**
 * 業務モジュールの権限の判定と定義の読み取りの呼び出しを数える口(テストの中だけ。判定と読み取りは元の口のまま)。判定の後に関数を挟める。
 */
final class CountingRecordFormQuery extends RecordFormQuery
{
    /** @var list<string> 判定の呼び出し(<業務モジュール> <操作>) */
    public array $allowsCalls = [];

    /** @var list<string> 定義の読み取りの呼び出し(<業務モジュール> <操作>) */
    public array $definitionCalls = [];

    /** @var (Closure(string, RecordModuleAction, bool): void)|null 判定の後に呼ぶ関数 */
    public ?Closure $afterAllows = null;

    public function allows(
        Requester $requester,
        string $moduleCode,
        RecordModuleAction $action,
        DateTimeInterface $date,
    ): bool {
        $this->allowsCalls[] = "{$moduleCode} {$action->value}";
        $allowed = parent::allows($requester, $moduleCode, $action, $date);

        if ($this->afterAllows !== null) {
            ($this->afterAllows)($moduleCode, $action, $allowed);
        }

        return $allowed;
    }

    public function definition(
        Requester $requester,
        string $moduleCode,
        RecordModuleAction $action,
        DateTimeInterface $date,
    ): array {
        $this->definitionCalls[] = "{$moduleCode} {$action->value}";

        return parent::definition($requester, $moduleCode, $action, $date);
    }
}
