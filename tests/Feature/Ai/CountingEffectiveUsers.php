<?php

namespace Tests\Feature\Ai;

use App\Queries\Master\Api\EffectiveUsers;
use DateTimeInterface;

/**
 * 有効な利用者の読み取りの呼び出しを数える口(テストの中だけ。読み取りは元の口のまま)。
 */
final class CountingEffectiveUsers extends EffectiveUsers
{
    public int $calls = 0;

    public function idsOn(array $userIds, DateTimeInterface $date): array
    {
        $this->calls++;

        return parent::idsOn($userIds, $date);
    }
}
