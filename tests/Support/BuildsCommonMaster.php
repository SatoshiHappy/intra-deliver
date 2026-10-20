<?php

namespace Tests\Support;

use App\Models\Authz\Role;
use App\Models\Authz\UserRole;
use App\Models\Master\CompanyPost;
use App\Models\Master\CompanyPostTerm;
use App\Models\Master\Department;
use App\Models\Master\DepartmentInclusion;
use App\Models\Master\DepartmentMembership;
use App\Models\Master\DepartmentMembershipPost;
use App\Models\Master\DepartmentSet;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use Carbon\CarbonImmutable;

/**
 * 読み取りのテストのデータを factory で作る手助け(期間化するマスタは期間の行も作る)。
 *
 * 期間の指定 = [開始日, 終了日(NULL = 上限なし), 無効か] の並び。既定は 2020-01-01 から上限なしの有効な 1 行。
 */
trait BuildsCommonMaster
{
    private const ALWAYS = [['2020-01-01', null, false]];

    protected static function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2?: bool}>  $terms
     */
    protected function department(DepartmentSet $set, array $terms = self::ALWAYS, bool $isRoot = false, int $sortKey = 0): Department
    {
        $department = Department::factory()->create(['department_set_id' => $set->id, 'is_root' => $isRoot, 'sort_key' => $sortKey]);

        foreach ($terms as $term) {
            DepartmentTerm::factory()->create(['department_id' => $department->id, ...$this->period($term)]);
        }

        return $department;
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2?: bool}>  $terms
     */
    protected function companyPost(DepartmentSet $set, int $rank, array $terms = self::ALWAYS): CompanyPost
    {
        $companyPost = CompanyPost::factory()->create(['department_set_id' => $set->id, 'rank' => $rank]);

        foreach ($terms as $term) {
            CompanyPostTerm::factory()->create(['company_post_id' => $companyPost->id, ...$this->period($term)]);
        }

        return $companyPost;
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2?: bool}>  $terms
     */
    protected function user(array $terms = self::ALWAYS): User
    {
        $user = User::factory()->create();

        foreach ($terms as $term) {
            UserTerm::factory()->create(['user_id' => $user->id, ...$this->period($term)]);
        }

        return $user;
    }

    /**
     * 内包(親子の組織は同じ組織セット)。
     *
     * @param  array{0: string, 1: string|null, 2?: bool}  $term
     */
    protected function include(Department $parent, Department $child, array $term = self::ALWAYS[0]): DepartmentInclusion
    {
        return DepartmentInclusion::factory()->create([
            'department_set_id' => $child->department_set_id,
            'parent_department_id' => $parent->id,
            'department_id' => $child->id,
            ...$this->period($term),
        ]);
    }

    /**
     * 所属。
     *
     * @param  array{0: string, 1: string|null, 2?: bool}  $term
     */
    protected function join(User $user, Department $department, array $term = self::ALWAYS[0], bool $isMain = false, int $sortKey = 0): DepartmentMembership
    {
        return DepartmentMembership::factory()->create([
            'department_set_id' => $department->department_set_id,
            'department_id' => $department->id,
            'user_id' => $user->id,
            'is_main' => $isMain,
            'sort_key' => $sortKey,
            ...$this->period($term),
        ]);
    }

    protected function assignPost(DepartmentMembership $membership, CompanyPost $companyPost): DepartmentMembershipPost
    {
        return DepartmentMembershipPost::factory()->create([
            'department_set_id' => $membership->department_set_id,
            'department_membership_id' => $membership->id,
            'company_post_id' => $companyPost->id,
        ]);
    }

    /**
     * ロールの付与。
     *
     * @param  array{0: string, 1: string|null, 2?: bool}  $term
     */
    protected function grant(User $user, Role $role, array $term = self::ALWAYS[0]): UserRole
    {
        return UserRole::factory()->create(['user_id' => $user->id, 'role_id' => $role->id, ...$this->period($term)]);
    }

    /**
     * @param  array{0: string, 1: string|null, 2?: bool}  $term
     * @return array{start_date: string, end_date: string|null, is_deleted: bool}
     */
    private function period(array $term): array
    {
        return ['start_date' => $term[0], 'end_date' => $term[1], 'is_deleted' => $term[2] ?? false];
    }
}
