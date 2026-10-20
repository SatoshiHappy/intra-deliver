<?php

namespace App\Queries\Authz;

use App\Models\Authz\Role;
use App\Models\Authz\RoleInclusion;
use App\Models\Authz\UserRole;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * ユーザーが持つロール(docs の 設計/共通マスタ/ロールと認可/ロール.md 2 節・3 節)。
 */
class RoleQuery
{
    /**
     * ユーザーが d に持つロール = d に成り立つ付与(付与の行とユーザーが d に有効。UserRole の holdsOn)のロールと、
     * そこからサブロールを親から子へたどったロール(親ロールを持つ人は子ロールもすべて持つ。逆向きにはたどらない)。
     * サブロールの循環は業務処理が止める(DB では止まらない)。止まらなかった循環でも終わるように、たどったロールを UNION で重ねない。
     * 並びは一覧の規則(共通の規則 9 節)のソートキー、コードの順。
     *
     * @return Collection<int, Role>
     */
    public function rolesOn(int $userId, DateTimeInterface $date): Collection
    {
        return $this->withSubRoles(UserRole::query()->where('user_id', $userId)->holdsOn($date)->select('role_id'));
    }

    /**
     * ロールのサブロールすべて(親から子へたどったロール。自分は含まない。循環があれば自分も入る)。
     * サブロールを付ける業務処理が循環を止めるのに使う。
     *
     * @return list<int>
     */
    public function subRoleIds(int $roleId): array
    {
        $children = RoleInclusion::query()->where('parent_role_id', $roleId)->select('child_role_id');

        return array_values($this->withSubRoles($children)->map(fn (Role $role) => $role->id)->all());
    }

    /**
     * 起点のロール(問い合わせが返す 1 列)と、そこからサブロールを親から子へたどったロール。
     *
     * @param  Builder<UserRole>|Builder<RoleInclusion>  $seeds
     * @return Collection<int, Role>
     */
    private function withSubRoles(Builder $seeds): Collection
    {
        $sql = sprintf(<<<'SQL'
            WITH RECURSIVE held (id) AS (
                %s
                UNION
                SELECT role_inclusions.child_role_id FROM role_inclusions JOIN held ON role_inclusions.parent_role_id = held.id
            )
            SELECT roles.* FROM roles JOIN held ON held.id = roles.id
            ORDER BY roles.sort_key, roles.code, roles.id
            SQL, $seeds->toSql());

        return Role::fromQuery($sql, $seeds->getBindings());
    }
}
