<?php

namespace App\Actions\Authz;

use App\Actions\Master\WritesCommonMaster;
use App\Models\Authz\RoleInclusion;
use App\Queries\Authz\RoleQuery;
use Illuminate\Validation\ValidationException;

/**
 * ロールにサブロールを付ける(docs の 設計/共通マスタ/ロールと認可/ロール.md 2 節)。親ロールを持つ人は子ロールもすべて持つ。
 *
 * 止める規則: 循環を作らない(子ロールのサブロールに親ロールがあれば拒む)。自分を子にしない・同じ組を 2 回付けないは DB が止める。
 */
class AddSubRole
{
    use WritesCommonMaster;

    public function __construct(private RoleQuery $roles) {}

    /**
     * @throws ValidationException 循環を作るとき
     */
    public function execute(int $parentRoleId, int $childRoleId): RoleInclusion
    {
        return $this->writeCommonMaster(function () use ($parentRoleId, $childRoleId) {
            if (in_array($parentRoleId, $this->roles->subRoleIds($childRoleId), true)) {
                throw ValidationException::withMessages(['child_role_id' => ['このサブロールを追加すると、ロールの親子が循環します']]);
            }

            return RoleInclusion::query()->create(['parent_role_id' => $parentRoleId, 'child_role_id' => $childRoleId]);
        });
    }
}
