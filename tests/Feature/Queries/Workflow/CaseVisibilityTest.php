<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowAction;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseVisibility;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の閲覧の判定(docs の 設計/ワークフロー/認可と一覧.md 2 節、7 節の「読み取りの Feature テスト」の 4 つの述語と閲覧の判定): 一時保存・未処理・処理済・参照の
 * 4 つの述語と、その和(閲覧できる)。案件・ノード・履歴・処理対象者の行はテストで作り、初期データのユーザー・組織・役職・ロールを使う。
 * 各述語の結果は、テストで作った案件だけに絞って比べる。
 */
final class CaseVisibilityTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsWorkflowCases;

    private const DATE = '2026-10-13';

    private const PREDICATES = ['draft', 'pending', 'processed', 'referenced'];

    /** @var list<int> テストで作った案件 */
    private array $caseIds = [];

    public function test_the_draft_predicate_is_the_own_draft(): void
    {
        [$a, $b] = $this->seededUsers('u0001', 'u0002');
        $draft = $this->draftCase($this->flow(), $a);
        $applied = $this->activeCase($this->flow(), $a);
        $this->process($this->caseNodes($applied, 2)[0], $a, WorkflowAction::Apply);

        $this->assertSame(['draft' => [$draft->id], 'pending' => [], 'processed' => [$applied->id], 'referenced' => []], $this->predicates($a));
        $this->assertSame(['draft' => [], 'pending' => [], 'processed' => [], 'referenced' => []], $this->predicates($b));
        $this->assertTrue($this->visibility()->isVisible($this->requester($a), self::day(), $draft->id));
        $this->assertFalse($this->visibility()->isVisible($this->requester($b), self::day(), $draft->id));
    }

    /**
     * 処理対象者の行は、承認待ち・再申請待ちのノードのものだけを見る(処理済み・到達していないノードに残った元の処理対象者の行は未処理に出ない)。
     */
    public function test_the_pending_predicate_is_the_target_of_the_current_node_only(): void
    {
        [$a, $b, $c, $d, $e] = $this->seededUsers('u0001', 'u0002', 'u0003', 'u0004', 'u0005');
        $case = $this->activeCase($this->flow(), $a);
        [$apply, $approve1, $approve2, $approve3] = $this->caseNodes($case, 4);
        $this->process($apply, $a, WorkflowAction::Apply);
        $this->process($approve1, $b, WorkflowAction::Approve);
        $this->target($approve1, $e);
        $this->reach($approve2, $c);
        $this->target($approve3, $d);

        $this->assertSame([$case->id], $this->idsOf('pending', $c));
        $this->assertSame([], $this->idsOf('pending', $b));
        $this->assertSame([], $this->idsOf('pending', $d));
        $this->assertSame([], $this->idsOf('pending', $e));
        $this->assertFalse($this->visibility()->isVisible($this->requester($d), self::day(), $case->id));
        $this->assertFalse($this->visibility()->isVisible($this->requester($e), self::day(), $case->id));
        $this->assertTrue($this->visibility()->isVisible($this->requester($c), self::day(), $case->id));
    }

    /**
     * 処理済は、自分の履歴の行があれば、そのノードが差戻し・引戻しで戻った後も出る。処理していない人には出ない。
     */
    public function test_the_processed_predicate_is_any_history_row_of_mine_even_after_the_node_went_back(): void
    {
        [$a, $b, $c, $d] = $this->seededUsers('u0001', 'u0002', 'u0003', 'u0004');
        $sentBack = $this->activeCase($this->flow(), $a);
        [$apply, $approve1, $approve2] = $this->caseNodes($sentBack, 3);
        $this->process($apply, $a, WorkflowAction::Apply);
        $this->process($approve1, $b, WorkflowAction::Approve);
        $this->process($approve2, $c, WorkflowAction::SendBack, sendBackTo: $apply);
        $this->unreach($approve1);
        $this->reach($apply, $a);
        $pulledBack = $this->activeCase($this->flow(), $a);
        [$apply, $approve1] = $this->caseNodes($pulledBack, 3);
        $this->process($apply, $a, WorkflowAction::Apply);
        $this->process($approve1, $b, WorkflowAction::Approve);
        $this->process($approve1, $b, WorkflowAction::PullBack);
        $this->reach($approve1, $b);

        $this->assertSame([$sentBack->id, $pulledBack->id], $this->idsOf('processed', $b));
        $this->assertSame([$sentBack->id, $pulledBack->id], $this->idsOf('processed', $a));
        $this->assertSame([$sentBack->id], $this->idsOf('processed', $c));
        $this->assertSame([], $this->idsOf('processed', $d));
        $this->assertSame([$sentBack->id], $this->idsOf('pending', $a));
        $this->assertSame([$pulledBack->id], $this->idsOf('pending', $b));
    }

    public function test_the_referenced_predicate_follows_the_referrers_of_the_flow(): void
    {
        [$a, $b, $c] = $this->seededUsers('u0001', 'u0002', 'u0003');
        $byUser = $this->activeCase($this->flow($this->selection(SelectionConditionKind::User, $c->id)), $a);
        $completed = $this->completedCase($this->flow($this->selection(SelectionConditionKind::User, $c->id)), $a);
        $byRole = $this->activeCase($this->flow($this->selection(SelectionConditionKind::Role, Role::query()->where('code', 'workflow_admin')->sole()->id)), $a);
        $withoutReferrers = $this->activeCase($this->flow(), $a);

        $this->assertSame([$byUser->id, $completed->id], $this->idsOf('referenced', $c));
        $this->assertSame([$byRole->id], $this->idsOf('referenced', $b));
        $this->assertSame([], $this->idsOf('referenced', $a));
        $this->assertFalse($this->visibility()->isVisible($this->requester($c), self::day(), $withoutReferrers->id));
    }

    /**
     * 組織・役職で指定した参照者は、カレント組織 1 つで判定する(切り替えると合う・合わなくなる)。
     */
    public function test_referrers_by_department_and_post_follow_the_current_department(): void
    {
        [$u0001, $u0009] = $this->seededUsers('u0001', 'u0009');
        $humanResources = Department::query()->where('code', 'human-resources')->sole();
        $planning = Department::query()->where('code', 'planning')->sole();
        $this->assignPost($this->join($u0001, $planning), CompanyPost::query()->where('code', 'senior')->sole());
        $byDepartment = $this->activeCase($this->flow($this->selection(SelectionConditionKind::Department, $humanResources->id)), $u0001);
        $byPost = $this->activeCase($this->flow($this->selection(SelectionConditionKind::CompanyPost, CompanyPost::query()->where('code', 'senior')->sole()->id)), $u0009);

        $this->assertSame([], $this->idsOf('referenced', $u0009));
        $this->assertSame([$byDepartment->id], $this->idsOf('referenced', $u0009, $humanResources->id));
        $this->assertSame([], $this->idsOf('referenced', $u0001));
        $this->assertSame([$byPost->id], $this->idsOf('referenced', $u0001, $planning->id));
    }

    /**
     * 未処理は完了の案件に出ない(完了の案件に承認待ちのノードと処理対象者の行が残っていても。業務処理の不変の条件を破った行で、状態の条件を確かめる)。
     * 参照と処理済は一時保存に出ない(一時保存に履歴の行があり、フローの参照者に合っても)。
     */
    public function test_pending_needs_an_active_case_and_processed_and_referenced_need_an_applied_case(): void
    {
        [$a, $b, $c] = $this->seededUsers('u0001', 'u0002', 'u0003');
        $completed = $this->completedCase($this->flow(), $a);
        $this->reach($this->caseNodes($completed, 2)[1], $b);
        $draft = $this->draftCase($this->flow($this->selection(SelectionConditionKind::User, $c->id)), $a);
        $this->process($this->caseNodes($draft, 1)[0], $b, WorkflowAction::Approve);

        $this->assertSame([], $this->idsOf('pending', $b));
        $this->assertSame([], $this->idsOf('processed', $b));
        $this->assertSame([], $this->idsOf('referenced', $c));
        $this->assertSame([$draft->id], $this->idsOf('draft', $a));
    }

    public function test_visible_is_the_union_of_the_four_predicates(): void
    {
        [$a, $b, $c, $d] = $this->seededUsers('u0001', 'u0002', 'u0003', 'u0004');
        $draft = $this->draftCase($this->flow(), $b);
        $pending = $this->activeCase($this->flow(), $a);
        $this->reach($this->caseNodes($pending, 2)[1], $b);
        $processed = $this->activeCase($this->flow(), $a);
        $this->process($this->caseNodes($processed, 2)[0], $b, WorkflowAction::Apply);
        $referenced = $this->completedCase($this->flow($this->selection(SelectionConditionKind::User, $b->id)), $a);
        $hidden = $this->activeCase($this->flow($this->selection(SelectionConditionKind::User, $c->id)), $d);

        $this->assertSame([$draft->id, $pending->id, $processed->id, $referenced->id], $this->idsOf('visible', $b));
        $this->assertFalse($this->visibility()->isVisible($this->requester($b), self::day(), $hidden->id));
        $this->assertFalse($this->visibility()->isVisible($this->requester($b), self::day(), 0));
    }

    /**
     * d に有効でないユーザー(退職)は、申請者・処理者・処理対象者・参照者の指定に当たっても何も見えない。
     */
    public function test_a_user_not_effective_on_the_date_sees_nothing(): void
    {
        [$retired] = $this->seededUsers('u0014');
        $this->draftCase($this->flow(), $retired);
        $case = $this->activeCase($this->flow($this->selection(SelectionConditionKind::User, $retired->id)), $retired);
        [$apply, $approve1] = $this->caseNodes($case, 2);
        $this->process($apply, $retired, WorkflowAction::Apply);
        $this->reach($approve1, $retired);

        $this->assertSame(['draft' => [], 'pending' => [], 'processed' => [], 'referenced' => []], $this->predicates($retired));
        $this->assertSame([], $this->idsOf('visible', $retired));
        $this->assertFalse($this->visibility()->isVisible($this->requester($retired), self::day(), $case->id));
    }

    public function test_a_guest_sees_nothing(): void
    {
        [$a] = $this->seededUsers('u0001');
        $this->draftCase($this->flow(), $a);
        $case = $this->activeCase($this->flow($this->selection(SelectionConditionKind::User, $a->id)), $a);
        $this->process($this->caseNodes($case, 1)[0], $a, WorkflowAction::Apply);

        $this->assertSame(['draft' => [], 'pending' => [], 'processed' => [], 'referenced' => []], $this->predicates(null));
        $this->assertSame([], $this->idsOf('visible', null));
        $this->assertFalse($this->visibility()->isVisible(new Requester(null, null), self::day(), $case->id));
    }

    private function visibility(): CaseVisibility
    {
        return app(CaseVisibility::class);
    }

    private static function day(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::DATE);
    }

    private function requester(?User $user, ?int $selectedDepartmentId = null): Requester
    {
        return new Requester($user?->id, null, $selectedDepartmentId);
    }

    /**
     * 述語(draft・pending・processed・referenced)か和(visible)で絞った、テストで作った案件の id(昇順)。
     *
     * @return list<int>
     */
    private function idsOf(string $predicate, ?User $user, ?int $selectedDepartmentId = null): array
    {
        $query = $this->visibility()->{$predicate}(WorkflowCase::query()->whereKey($this->caseIds), $this->requester($user, $selectedDepartmentId), self::day());

        return array_values(array_map('intval', $query->orderBy('id')->pluck('id')->all()));
    }

    /**
     * @return array<string, list<int>> 述語 → 案件の id
     */
    private function predicates(?User $user): array
    {
        return array_combine(self::PREDICATES, array_map(fn (string $predicate) => $this->idsOf($predicate, $user), self::PREDICATES));
    }

    /**
     * フロー(参照者の指定 = 渡した対象者。null なら参照者なし)。
     */
    private function flow(?int $referrerSubjectGroupId = null): WorkflowFlow
    {
        return WorkflowFlow::factory()->create(['authz_subject_group_id' => $referrerSubjectGroupId]);
    }

    private function draftCase(WorkflowFlow $flow, User $applicant): WorkflowCase
    {
        return $this->remember(WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $flow->id, 'user_id' => $applicant->id]));
    }

    private function activeCase(WorkflowFlow $flow, User $applicant): WorkflowCase
    {
        return $this->remember(WorkflowCase::factory()->active()->create(['workflow_flow_id' => $flow->id, 'user_id' => $applicant->id]));
    }

    private function completedCase(WorkflowFlow $flow, User $applicant): WorkflowCase
    {
        return $this->remember(WorkflowCase::factory()->completed()->create(['workflow_flow_id' => $flow->id, 'user_id' => $applicant->id]));
    }

    private function remember(WorkflowCase $case): WorkflowCase
    {
        $this->caseIds[] = $case->id;

        return $case;
    }
}
