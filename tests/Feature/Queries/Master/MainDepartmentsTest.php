<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Queries\Master\Api\MasterCandidates;
use App\Queries\Master\Api\MasterNames;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * ユーザーの主所属の組織({id, name})の公開の口(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の mainDepartments)。
 * 主所属の意味と存在しない id の扱いは mainDepartmentNames と同じ。初期データ(u0009 = 総務部が主所属)で確かめる。
 */
final class MainDepartmentsTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    public function test_returns_the_id_and_the_name_in_the_locale_of_the_main_department(): void
    {
        $manager = $this->userId('u0009');
        $department = Department::query()->where('code', 'general-affairs')->sole()->id;
        $today = self::day('2026-10-10');
        $departments = fn (string $localeCode) => $this->names()->mainDepartments([$manager], $today, $localeCode);

        $this->assertSame([$manager => ['id' => $department, 'name' => '総務部']], $departments('ja'));
        $this->assertSame([$manager => ['id' => $department, 'name' => 'General Affairs Dept.']], $departments('en'));
        $this->assertSame([$manager => ['id' => $department, 'name' => null]], $departments('fr'));
    }

    public function test_is_null_without_a_main_membership_in_the_tree_and_leaves_out_ids_that_do_not_exist(): void
    {
        $missing = (int) User::query()->max('id') + 1;
        $without = $this->user();
        $outside = $this->user();
        $this->join($outside, $this->department(DepartmentSet::factory()->create()), isMain: true);
        $userIds = [$missing, $without->id, $outside->id, $without->id];

        $this->assertSame(
            [$without->id => null, $outside->id => null],
            $this->names()->mainDepartments($userIds, self::day('2026-10-10'), 'ja'),
        );
        $this->assertSame([], $this->names()->mainDepartments([], self::day('2026-10-10'), 'ja'));
    }

    public function test_the_names_follow_the_same_rule_as_main_department_names(): void
    {
        $today = self::day('2026-10-10');
        $candidates = app(MasterCandidates::class)->usersOn('u00', $today, 'ja', 20);
        $userIds = array_column($candidates, 'id');
        $departments = $this->names()->mainDepartments($userIds, $today, 'ja');

        $this->assertSame(
            $this->names()->mainDepartmentNames($userIds, $today, 'ja'),
            array_map(fn (?array $department) => $department['name'] ?? null, $departments),
        );
    }

    private function names(): MasterNames
    {
        return app(MasterNames::class);
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }
}
