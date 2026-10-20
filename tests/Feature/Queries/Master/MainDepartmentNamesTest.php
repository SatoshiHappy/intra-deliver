<?php

namespace Tests\Feature\Queries\Master;

use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Queries\Master\Api\MasterCandidates;
use App\Queries\Master\Api\MasterNames;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * ユーザーの主所属の名前の公開の口(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1 の mainDepartmentNames。意味は
 * 設計/共通マスタ/検索と組織図.md 2 節の「ユーザーの主所属」)。初期データ(u0009 = 総務部が主所属で人事部を兼務、u0014 = 経理部が主所属で
 * 2025-10-01 に退職)で確かめる。
 */
final class MainDepartmentNamesTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    public function test_returns_the_name_of_the_main_department_in_the_locale(): void
    {
        $manager = $this->userId('u0009');
        $today = self::day('2026-10-10');
        $names = fn (string $localeCode) => $this->names()->mainDepartmentNames([$manager], $today, $localeCode);

        $this->assertSame([$manager => '総務部'], $names('ja'));
        $this->assertSame([$manager => 'General Affairs Dept.'], $names('en'));
        $this->assertSame([$manager => null], $names('fr'));
    }

    public function test_a_membership_outside_its_period_is_not_the_main_department(): void
    {
        $retired = $this->userId('u0014');
        $names = fn (string $date) => $this->names()->mainDepartmentNames([$retired], self::day($date), 'ja');

        $this->assertSame([$retired => '経理部'], $names('2025-09-30'));
        $this->assertSame([$retired => null], $names('2025-10-01'));
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
            $this->names()->mainDepartmentNames($userIds, self::day('2026-10-10'), 'ja'),
        );
        $this->assertSame([], $this->names()->mainDepartmentNames([], self::day('2026-10-10'), 'ja'));
    }

    public function test_follows_the_same_rule_as_the_main_department_of_the_candidates(): void
    {
        $today = self::day('2026-10-10');
        $candidates = app(MasterCandidates::class)->usersOn('u00', $today, 'ja', 20);
        $expected = array_column($candidates, 'mainDepartmentName', 'id');

        $this->assertContains('総務部', $expected);
        $this->assertSame($expected, $this->names()->mainDepartmentNames(array_keys($expected), $today, 'ja'));
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
