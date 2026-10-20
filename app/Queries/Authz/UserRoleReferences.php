<?php

namespace App\Queries\Authz;

use App\Models\Authz\UserRole;
use App\Queries\Master\UserReferences;

/**
 * ロールの付与(user_roles)がユーザーを指す有効な期間(docs の 設計/共通マスタ/ロールと認可/ロール.md 3 節: 付与が d に有効ならユーザーも d に有効)。
 * Master の業務処理がユーザーの有効な日を減らすときに、付与がはみ出さないかを確かめるのに使う(起動がタグで登録する)。
 */
class UserRoleReferences implements UserReferences
{
    public function effectivePeriodsReferencing(int $userId): array
    {
        return UserRole::query()->where('user_id', $userId)->get()
            ->map(fn (UserRole $grant) => $grant->effectivePeriod())
            ->filter()
            ->values()
            ->all();
    }
}
