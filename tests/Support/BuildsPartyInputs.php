<?php

namespace Tests\Support;

use App\Models\Master\Department;
use App\Models\Master\User;

/**
 * 型「ユーザーと組織」の項目への相手の入力({type, id})を、ユーザー・組織から作るテストの手助け。
 */
trait BuildsPartyInputs
{
    /**
     * @return array{type: string, id: int}
     */
    private static function partyInputOf(User|Department $party): array
    {
        return ['type' => $party instanceof User ? 'user' : 'department', 'id' => $party->id];
    }
}
