<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Queries\Authz\JudgeRequesters;
use App\Queries\Authz\SubjectQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 判定する人を替えた判定を求める者(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.3): ユーザーは引数、接続元は判定の入力のもの、
 * 選んだ組織は null(組織の条件は主所属で判定される)。初期データ(兼務の u0009 = 総務部が主所属、人事部を兼務)で確かめる。
 */
final class JudgeRequestersTest extends TransactionalTestCase
{
    private const DATE = '2026-10-10';

    public function test_takes_the_user_and_the_address_without_a_selected_department(): void
    {
        $requester = JudgeRequesters::forUserIn($this->context(selectedDepartmentId: 123), 45);

        $this->assertSame(45, $requester->userId);
        $this->assertSame('192.168.0.1', $requester->ipv4Address);
        $this->assertNull($requester->selectedDepartmentId);
        $this->assertNull(JudgeRequesters::forUserIn($this->context(), null)->userId);
    }

    public function test_a_concurrent_user_matches_the_department_of_the_main_membership_only(): void
    {
        $generalAffairs = $this->subjectGroupForDepartment('general-affairs');
        $humanResources = $this->subjectGroupForDepartment('human-resources');
        $user = User::query()->where('code', 'u0009')->sole();
        // 判定の入力で人事部を選んでいても、forUserIn は選んだ組織を持たない
        $requester = JudgeRequesters::forUserIn($this->context(selectedDepartmentId: $this->departmentId('human-resources')), $user->id);

        $matching = app(SubjectQuery::class)->matchingSubjectGroupIds($requester, [$generalAffairs->id, $humanResources->id], CarbonImmutable::parse(self::DATE));

        $this->assertSame([$generalAffairs->id], $matching);
    }

    private function context(?int $selectedDepartmentId = null): AccessContext
    {
        return new AccessContext(
            userId: null,
            ipv4Address: '192.168.0.1',
            selectedDepartmentId: $selectedDepartmentId,
            date: CarbonImmutable::parse(self::DATE),
            localeCode: 'ja',
        );
    }

    private function subjectGroupForDepartment(string $code): AuthzSubjectGroup
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => SubjectCombination::Any]);
        AuthzSubjectCondition::factory()->forDepartment()->create(['authz_subject_group_id' => $group->id, 'department_id' => $this->departmentId($code)]);

        return $group;
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }
}
