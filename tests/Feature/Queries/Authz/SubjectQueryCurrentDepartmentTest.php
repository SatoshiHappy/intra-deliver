<?php

namespace Tests\Feature\Queries\Authz;

use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Subject;
use App\Queries\Authz\SubjectQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 所属から来る事実(組織・その上位と下位・役職)は、カレント組織の所属 1 つだけから作る(docs の 設計/デモユーザー切替/今の利用者.md 5.5。
 * 対象者.md の「条件に合うか」)。カレント組織 = 選んだ組織が候補(d に成り立つ既定の組織セットの所属)にあればその所属、
 * 無ければ候補のうち主所属、それも無ければ無い。
 *
 * 組織の木 = 頂点 R > A、R > B > C(既定の組織セット)。兼務の利用者は A に主所属(課長)、B に兼務(主任)。
 * 条件と組み合わせた判定は SubjectQueryTest。
 */
final class SubjectQueryCurrentDepartmentTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    private DepartmentSet $set;

    private User $user;

    /** @var array<string, Department> */
    private array $departments = [];

    /** @var array<string, CompanyPost> */
    private array $posts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->set = DepartmentSet::factory()->create(['is_default' => true]);
        $this->departments['R'] = $this->department($this->set, isRoot: true);

        foreach (['A' => 'R', 'B' => 'R', 'C' => 'B'] as $name => $parent) {
            $this->departments[$name] = $this->department($this->set);
            $this->include($this->departments[$parent], $this->departments[$name]);
        }

        $this->posts['課長'] = $this->companyPost($this->set, 30);
        $this->posts['主任'] = $this->companyPost($this->set, 50);
        $this->user = $this->user();
        $this->assignPost($this->join($this->user, $this->departments['A'], isMain: true), $this->posts['課長']);
        $this->assignPost($this->join($this->user, $this->departments['B']), $this->posts['主任']);
    }

    /**
     * @return array<string, array{string|null, string, list<string>, list<string>, string}>
     */
    public static function selections(): array
    {
        return [
            '選んでいない → 主所属' => [null, 'A', ['R'], [], '課長'],
            '兼務の組織を選ぶ' => ['B', 'B', ['R'], ['C'], '主任'],
            '主所属の組織を選ぶ' => ['A', 'A', ['R'], [], '課長'],
            '所属していない組織を選ぶ → 主所属' => ['C', 'A', ['R'], [], '課長'],
        ];
    }

    /**
     * @param  list<string>  $upper
     * @param  list<string>  $lower
     */
    #[DataProvider('selections')]
    public function test_takes_the_department_and_the_post_only_from_the_membership_in_the_current_department(?string $selected, string $current, array $upper, array $lower, string $post): void
    {
        $subject = $this->subjectOf($this->user, $selected === null ? null : $this->departments[$selected]->id);

        $this->assertSame([$this->departments[$current]->id], $subject->departmentIds);
        $this->assertSame($this->departmentIds($upper), $subject->upperDepartmentIds);
        $this->assertSame($this->departmentIds($lower), $subject->lowerDepartmentIds);
        $this->assertSame([$this->posts[$post]->id], $subject->companyPosts->modelKeys());
    }

    public function test_has_no_current_department_without_a_main_membership_unless_one_is_selected(): void
    {
        $user = $this->user();
        $this->assignPost($this->join($user, $this->departments['B']), $this->posts['主任']);

        $this->assertNoCurrentDepartment($this->subjectOf($user, null));
        $this->assertSame([$this->departments['B']->id], $this->subjectOf($user, $this->departments['B']->id)->departmentIds);
    }

    public function test_a_membership_in_a_department_set_that_is_not_the_default_is_not_a_candidate(): void
    {
        $otherSet = DepartmentSet::factory()->create();
        $department = $this->department($otherSet, isRoot: true);
        $user = $this->user();
        $this->assignPost($this->join($user, $department, isMain: true), $this->companyPost($otherSet, 30));

        $this->assertNoCurrentDepartment($this->subjectOf($user, null));
        $this->assertNoCurrentDepartment($this->subjectOf($user, $department->id));
        $this->assertSame($user->id, $this->subjectOf($user, null)->userId);
    }

    private function subjectOf(User $user, ?int $selectedDepartmentId): Subject
    {
        return app(SubjectQuery::class)->subjectOn(new Requester($user->id, null, $selectedDepartmentId), self::day(self::DATE));
    }

    private function assertNoCurrentDepartment(Subject $subject): void
    {
        $this->assertSame([[], [], [], []], [$subject->departmentIds, $subject->upperDepartmentIds, $subject->lowerDepartmentIds, $subject->companyPosts->modelKeys()]);
    }

    /**
     * @param  list<string>  $names
     * @return list<int>
     */
    private function departmentIds(array $names): array
    {
        return array_map(fn (string $name) => $this->departments[$name]->id, $names);
    }
}
