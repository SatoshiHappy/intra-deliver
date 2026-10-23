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
        return $this->rolesOfEach([$userId], $date)[$userId];
    }

    /**
     * ユーザーそれぞれが d に持つロールの id({@see self::rolesOn()} と同じ並び)。
     *
     * @param  list<int>  $userIds
     * @return array<int, list<int>> キー = 渡したユーザーすべて
     */
    public function roleIdsOfEach(array $userIds, DateTimeInterface $date): array
    {
        return array_map(fn (Collection $roles) => $this->roleIdsOf($roles), $this->rolesOfEach($userIds, $date));
    }

    /**
     * ロールのサブロールすべて(親から子へたどったロール。自分は含まない。循環があれば自分も入る)。
     * サブロールを付ける業務処理が循環を止めるのに使う。
     *
     * @return list<int>
     */
    public function subRoleIds(int $roleId): array
    {
        $children = RoleInclusion::query()
            ->where('parent_role_id', $roleId)
            ->select(['parent_role_id', 'child_role_id']);

        $subRoles = $this->withSubRolesOfEach($children, [$roleId])[$roleId];

        return $this->roleIdsOf($subRoles);
    }

    /**
     * ユーザーそれぞれが d に持つロール(並びは {@see self::rolesOn()} の説明のとおり)。
     *
     * @param  list<int>  $userIds
     * @return array<int, Collection<int, Role>> キー = 渡したユーザーすべて
     */
    private function rolesOfEach(array $userIds, DateTimeInterface $date): array
    {
        $grants = UserRole::query()
            ->whereRaw('user_roles.user_id = ANY (?::bigint[])', ['{'.implode(',', $userIds).'}'])
            ->holdsOn($date)
            ->select(['user_id', 'role_id']);

        return $this->withSubRolesOfEach($grants, $userIds);
    }

    /**
     * 持ち主それぞれの起点のロール(問い合わせが返す 2 列 = 持ち主・ロール)と、そこからサブロールを親から子へたどったロール。
     * 持ち主の列は返すロールの属性に残さない(1 人の口が返すロールの属性を、持ち主を持たずにたどっていたときと変えないため)。
     *
     * @param  Builder<UserRole>|Builder<RoleInclusion>  $seeds
     * @param  list<int>  $ownerIds
     * @return array<int, Collection<int, Role>> キー = 渡した持ち主すべて(無ければ空)
     */
    private function withSubRolesOfEach(Builder $seeds, array $ownerIds): array
    {
        $held = array_fill_keys($ownerIds, []);

        if ($held === []) {
            return [];
        }

        $sql = sprintf(<<<'SQL'
            WITH RECURSIVE held (owner_id, id) AS (
                %s
                UNION
                SELECT held.owner_id, role_inclusions.child_role_id FROM role_inclusions
                JOIN held ON role_inclusions.parent_role_id = held.id
            )
            SELECT held.owner_id AS held_by_id, roles.* FROM roles JOIN held ON held.id = roles.id
            ORDER BY roles.sort_key, roles.code, roles.id
            SQL, $seeds->toSql());

        $connection = Role::query()->getConnection();

        foreach ($connection->select($sql, $seeds->getBindings()) as $row) {
            $attributes = (array) $row;
            $ownerId = intval($attributes['held_by_id']);
            $held[$ownerId][] = array_diff_key($attributes, ['held_by_id' => true]);
        }

        return array_map(fn (array $roles) => Role::query()->hydrate($roles), $held);
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return list<int>
     */
    private function roleIdsOf(Collection $roles): array
    {
        $ids = $roles->map(fn (Role $role) => $role->id)->all();

        return array_values($ids);
    }
}
