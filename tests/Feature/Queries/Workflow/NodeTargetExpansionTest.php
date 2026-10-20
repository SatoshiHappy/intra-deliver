<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\NodeTargetExpansion;
use Carbon\CarbonImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 処理対象者の展開(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 4 節、同時実行と検査.md 14 節の「処理対象者」)。
 * 期待は共通マスタの初期データ(組織とユーザー.md 9 節の引き方)から書く: 東京営業課 = u0001・u0002(課長)、その上 = 第一営業部(u0003 部長・u0013)、
 * 営業本部(u0006 本部長)、根 = 会社(u0005 社長)。u0009 は人事部を兼務(部長)。2025-04-01 の再編で u0013 が開発部 → 第一営業部、u0012 が係長 → 課長、
 * 品質保証部の上が開発本部 → 管理本部。u0014 は 2025-10-01 に退職、u0015 は 2026-11-01 に入社。
 */
final class NodeTargetExpansionTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;

    private const DAY = '2026-10-13';

    /**
     * @return array<string, array{string|null, WorkflowRelativeScope, string|null, string, list<string>}>
     */
    public static function relativeCases(): array
    {
        return [
            '所属組織・役職なし' => ['tokyo-sales', WorkflowRelativeScope::Own, null, self::DAY, ['u0001', 'u0002']],
            '所属組織・課長' => ['tokyo-sales', WorkflowRelativeScope::Own, 'section-chief', self::DAY, ['u0002']],
            '上位組織・役職なし' => ['tokyo-sales', WorkflowRelativeScope::Parent, null, self::DAY, ['u0003', 'u0013']],
            '上位組織・部長' => ['tokyo-sales', WorkflowRelativeScope::Parent, 'manager', self::DAY, ['u0003']],
            '上位組織全て・役職なし' => ['tokyo-sales', WorkflowRelativeScope::Ancestors, null, self::DAY, ['u0003', 'u0005', 'u0006', 'u0013']],
            '上位組織全て・部長' => ['tokyo-sales', WorkflowRelativeScope::Ancestors, 'manager', self::DAY, ['u0003']],
            '上位組織全て・本部長' => ['tokyo-sales', WorkflowRelativeScope::Ancestors, 'division-head', self::DAY, ['u0006']],
            '根の組織の上位組織は 0 人' => ['deliver', WorkflowRelativeScope::Parent, null, self::DAY, []],
            '根の組織の上位組織全ては 0 人' => ['deliver', WorkflowRelativeScope::Ancestors, null, self::DAY, []],
            '起点の組織が無ければ 0 人' => [null, WorkflowRelativeScope::Own, null, self::DAY, []],
            '兼務の人が入る' => ['human-resources', WorkflowRelativeScope::Own, 'manager', self::DAY, ['u0009']],
            '異動の前の所属' => ['development', WorkflowRelativeScope::Own, null, '2025-03-31', ['u0011', 'u0012', 'u0013']],
            '異動の後の所属' => ['development', WorkflowRelativeScope::Own, null, '2025-04-01', ['u0011', 'u0012']],
            '役職の変わる前' => ['development', WorkflowRelativeScope::Own, 'section-chief', '2025-03-31', []],
            '役職の変わった後' => ['development', WorkflowRelativeScope::Own, 'section-chief', '2025-04-01', ['u0012']],
            '再編の前の上位組織' => ['quality-assurance', WorkflowRelativeScope::Parent, 'division-head', '2025-03-31', ['u0008']],
            '再編の後の上位組織' => ['quality-assurance', WorkflowRelativeScope::Parent, 'division-head', '2025-04-01', ['u0007']],
            '退職の前日は入る' => ['accounting', WorkflowRelativeScope::Own, null, '2025-09-30', ['u0010', 'u0014']],
            '退職の日からは入らない' => ['accounting', WorkflowRelativeScope::Own, null, '2025-10-01', ['u0010']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('relativeCases')]
    public function test_relative_expands_the_scope_and_the_post_on_the_date(?string $origin, WorkflowRelativeScope $scope, ?string $post, string $date, array $expected): void
    {
        $userIds = $this->expansion()->relative($scope, $post === null ? null : $this->postId($post), $origin === null ? null : $this->departmentId($origin), CarbonImmutable::parse($date));

        $this->assertSame($this->userIds(...$expected), $userIds);
    }

    public function test_expand_picks_the_origin_by_the_base_of_the_node(): void
    {
        $node = fn (WorkflowRelativeBase $base) => $this->relativeNode($base, WorkflowRelativeScope::Own, 'manager');
        $applicantDepartment = $this->departmentId('sales-1');
        $previousDepartment = $this->departmentId('accounting');

        $this->assertSame($this->userIds('u0003'), $this->expansion()->expand($node(WorkflowRelativeBase::Applicant), $applicantDepartment, $previousDepartment, $this->day()));
        $this->assertSame($this->userIds('u0010'), $this->expansion()->expand($node(WorkflowRelativeBase::Previous), $applicantDepartment, $previousDepartment, $this->day()));
        $this->assertSame([], $this->expansion()->expand($node(WorkflowRelativeBase::Applicant), null, $previousDepartment, $this->day()));
    }

    public function test_expand_a_fixed_node_returns_the_subject_members_on_the_date(): void
    {
        $node = new WorkflowCaseNode(['kind' => WorkflowNodeKind::Approve, 'target_kind' => WorkflowTargetKind::Fixed, 'authz_subject_group_id' => $this->selection(SelectionConditionKind::Department, $this->departmentId('accounting'))]);

        $this->assertSame($this->userIds('u0010', 'u0014'), $this->expansion()->expand($node, null, null, CarbonImmutable::parse('2025-09-30')));
        $this->assertSame($this->userIds('u0010'), $this->expansion()->expand($node, null, null, $this->day()));
    }

    public function test_a_designated_node_is_not_expanded_here(): void
    {
        $node = new WorkflowCaseNode(['kind' => WorkflowNodeKind::Approve, 'target_kind' => WorkflowTargetKind::Designated]);

        $this->expectException(LogicException::class);
        $this->expansion()->expand($node, null, null, $this->day());
    }

    public function test_the_origin_of_the_previous_actor_is_the_selected_department_or_the_main_one(): void
    {
        [$u0009, $u0014] = $this->seededUsers('u0009', 'u0014');

        $this->assertSame($this->departmentId('human-resources'), $this->expansion()->originDepartmentOn($u0009->id, $this->departmentId('human-resources'), $this->day()));
        $this->assertSame($this->departmentId('general-affairs'), $this->expansion()->originDepartmentOn($u0009->id, null, $this->day()));
        $this->assertSame($this->departmentId('general-affairs'), $this->expansion()->originDepartmentOn($u0009->id, $this->departmentId('tokyo-sales'), $this->day()));
        $this->assertNull($this->expansion()->originDepartmentOn($u0014->id, null, $this->day()));
    }

    public function test_all_effective_on_checks_every_user_on_the_date(): void
    {
        $this->assertTrue($this->expansion()->allEffectiveOn($this->userIds('u0001', 'u0002'), $this->day()));
        $this->assertTrue($this->expansion()->allEffectiveOn([], $this->day()));
        $this->assertFalse($this->expansion()->allEffectiveOn($this->userIds('u0001', 'u0014'), $this->day()));
        $this->assertTrue($this->expansion()->allEffectiveOn($this->userIds('u0014'), CarbonImmutable::parse('2025-09-30')));
        $this->assertFalse($this->expansion()->allEffectiveOn($this->userIds('u0015'), $this->day()));
    }

    private function relativeNode(WorkflowRelativeBase $base, WorkflowRelativeScope $scope, ?string $post): WorkflowCaseNode
    {
        return new WorkflowCaseNode([
            'kind' => WorkflowNodeKind::Approve, 'target_kind' => WorkflowTargetKind::Relative, 'relative_base' => $base, 'relative_scope' => $scope,
            'company_post_id' => $post === null ? null : $this->postId($post),
        ]);
    }

    private function expansion(): NodeTargetExpansion
    {
        return app(NodeTargetExpansion::class);
    }

    private function day(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::DAY);
    }

    /**
     * @return list<int> 昇順
     */
    private function userIds(string ...$codes): array
    {
        $ids = array_map(fn (string $code) => User::query()->where('code', $code)->sole()->id, array_values($codes));
        sort($ids);

        return $ids;
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }

    private function postId(string $code): int
    {
        return CompanyPost::query()->where('code', $code)->sole()->id;
    }
}
