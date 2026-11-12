<?php

namespace App\Actions\Ai\ToolCalls;

use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Authz\Api\Requester;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Records\Api\RecordFormQuery;
use DateTimeInterface;

/**
 * 判定の関数の名前空間が、使い回さない区間の中で依頼者の有効と業務モジュールの権限を判定し、定義を読むのは通る
 */
final class JudgesModule
{
    public function __construct(private RecordFormQuery $forms, private EffectiveUsers $users, private AuthorizationReuse $reuse) {}

    /** @return array<string, mixed>|null */
    public function definitionFor(Requester $requester, int $userId, DateTimeInterface $date): ?array
    {
        return $this->reuse->without(function () use ($requester, $userId, $date): ?array {
            if ($this->users->idsOn([$userId], $date) === [] || ! $this->forms->allows($requester, 'tasks', RecordModuleAction::Read, $date)) {
                return null;
            }

            return $this->forms->definition($requester, 'tasks', RecordModuleAction::Read, $date);
        });
    }
}
