<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseOperationDecider;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;
use Tests\Unit\Queries\Workflow\CaseOperationTableTest;

/**
 * 案件の操作の判定と can(docs の 設計/ワークフロー/案件の操作/操作.md 3.2 の表と引戻しの 1 規則・README.md 1.3 の役割、
 * 設計/ワークフロー/認可と一覧.md 3.2、画面/README.md 1.2・画面/申請と案件.md 5 節)。
 * 表そのものは {@see CaseOperationTableTest}、役割ごとの表は {@see CaseOperationDeciderPermitsInTest}。ここは本番の口(permitsIn・rowCansOf・detailCanOf・
 * isUpdatable)を、案件・ノード・履歴・処理対象者の行をテストで作って確かめる(初期データのユーザー u0001〜を使う)。
 */
final class CaseOperationDeciderTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use BuildsWorkflowCases;
    use UsesSeededRecords;

    private const DATE = '2026-10-13';

    /**
     * 行・詳細の can は表と 403 の判定(申請できる人・業務モジュールの update)の AND。実際の案件で本番の rowCansOf・detailCanOf を通す。
     */
    public function test_the_row_can_ands_the_table_with_the_403_judgements(): void
    {
        [$a, $b] = $this->seededUsers('u0001', 'u0002');
        $denied = $this->flow('wf_can_denied', $this->selection(SelectionConditionKind::User, $a->id));
        $allowed = $this->flow('wf_can_allowed', $this->selection(SelectionConditionKind::User, $a->id));
        $deniedDraft = WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $denied->id, 'user_id' => $a->id]);
        $allowedDraft = WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $allowed->id, 'user_id' => $a->id]);
        $reapply = $this->activeCase($denied, $a);
        [$apply, $approve1] = $this->caseNodes($reapply, 2);
        $this->process($apply, $a, WorkflowAction::Apply);
        $this->process($approve1, $b, WorkflowAction::SendBack, $apply);
        $this->reach($apply, $a);
        $this->deny('wf_can_denied', RecordModuleAction::Create);
        $this->deny('wf_can_denied', RecordModuleAction::Update);

        $this->assertSame([
            $deniedDraft->id => ['pullBack' => false, 'reapply' => false, 'withdraw' => false, 'editDraft' => false, 'deleteDraft' => true],
            $allowedDraft->id => ['pullBack' => false, 'reapply' => false, 'withdraw' => false, 'editDraft' => true, 'deleteDraft' => true],
            $reapply->id => ['pullBack' => false, 'reapply' => false, 'withdraw' => true, 'editDraft' => false, 'deleteDraft' => false],
        ], $this->rowCans($a, [$deniedDraft, $allowedDraft, $reapply]));
        $this->assertSame(['pullBack' => false, 'reapply' => false, 'withdraw' => true], $this->decider()->detailCanOf($this->requester($a), self::day(), $this->decider()->situationsOf($this->requester($a), self::day(), [$reapply])[$reapply->id]));
    }

    /**
     * 引戻しできる処理者は、ノードの最新の行が自分の引戻せる種別のときだけ(設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.5)。承認 1 を承認した b だけが承認 1 を引戻せ、
     * 引戻した後は b が承認 1 の処理対象者で、誰もどのノードも引戻せない(最新の行が引戻し)。
     */
    public function test_the_pull_back_actor_is_only_the_actor_of_the_latest_row_of_a_pull_backable_kind(): void
    {
        [$a, $b] = $this->seededUsers('u0001', 'u0002');
        $case = $this->activeCase($this->flow('wf_pull_back', $this->selection(SelectionConditionKind::User, $a->id)), $a);
        $nodes = $this->caseNodes($case, 3);
        $this->process($nodes[0], $a, WorkflowAction::Apply);
        $this->process($nodes[1], $b, WorkflowAction::Approve);
        $pullBacks = fn (User $user) => array_map(fn (WorkflowCaseNode $node) => $this->permitsIn(WorkflowOperation::PullBack, $user, $case, $node->id), $nodes);
        $this->assertSame([[false, false, false], [false, true, false]], [$pullBacks($a), $pullBacks($b)]);

        $this->process($nodes[1], $b, WorkflowAction::PullBack);
        $this->reach($nodes[1], $b);

        $this->assertSame([[false, false, false], [false, false, false]], [$pullBacks($a), $pullBacks($b)]);
        $this->assertSame([true, true], [$this->permitsIn(WorkflowOperation::Approve, $b, $case, $nodes[1]->id), $this->decider()->isUpdatable($this->requester($b), self::day(), $case->workflow_flow_id)]);
    }

    /**
     * d に有効でない人(u0014 = 退職)は、有効ならすべての役割に当たる場面(本人の一時保存、本人が申請して承認 1 の処理対象者で申請を引戻せる、
     * 本人の再申請待ち。申請のノードの対象者で業務モジュールの 4 つを許される)でも、どの判定も偽。
     */
    public function test_a_person_not_effective_on_the_date_has_no_role(): void
    {
        [$retired, $b] = $this->seededUsers('u0014', 'u0002');
        $flow = $this->flow('wf_retired', $this->selection(SelectionConditionKind::User, $retired->id));
        $draft = WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $flow->id, 'user_id' => $retired->id]);
        [$case, $reapply] = [$this->activeCase($flow, $retired), $this->activeCase($flow, $retired)];
        [[$apply, $approve1], [$reapplyApply, $reapplyApprove1]] = [$this->caseNodes($case, 2), $this->caseNodes($reapply, 2)];
        $this->process($apply, $retired, WorkflowAction::Apply);
        $this->reach($approve1, $retired);
        $this->process($reapplyApply, $retired, WorkflowAction::Apply);
        $this->process($reapplyApprove1, $b, WorkflowAction::SendBack, $reapplyApply);
        $this->reach($reapplyApply, $retired);
        $none = ['pullBack' => false, 'reapply' => false, 'withdraw' => false, 'editDraft' => false, 'deleteDraft' => false];

        $this->assertSame([false, false, false, false, false, false, false], [
            $this->permitsIn(WorkflowOperation::OverwriteDraft, $retired, $draft, null), $this->permitsIn(WorkflowOperation::DeleteDraft, $retired, $draft, null),
            $this->permitsIn(WorkflowOperation::Approve, $retired, $case, $approve1->id), $this->permitsIn(WorkflowOperation::PullBack, $retired, $case, $apply->id),
            $this->permitsIn(WorkflowOperation::Reapply, $retired, $reapply, $reapplyApply->id), $this->permitsIn(WorkflowOperation::Withdraw, $retired, $reapply, $reapplyApply->id),
            $this->decider()->isUpdatable($this->requester($retired), self::day(), $flow->id),
        ]);
        $this->assertSame([$draft->id => $none, $case->id => $none, $reapply->id => $none], $this->rowCans($retired, [$draft, $case, $reapply]));
    }

    /**
     * 行の can は表と同じ判定で、問い合わせの回数は行の数に依らない(同じフローの案件が 1 件でも 3 件でも同じ回数)。
     */
    public function test_row_cans_use_the_table_and_a_fixed_number_of_queries(): void
    {
        [$a, $b] = $this->seededUsers('u0001', 'u0002');
        $flow = $this->flow('wf_rows', $this->selection(SelectionConditionKind::User, $a->id));
        $cases = [];

        foreach (range(1, 3) as $ignored) {
            $case = $this->activeCase($flow, $a);
            [$apply, $approve1] = $this->caseNodes($case, 2);
            $this->process($apply, $a, WorkflowAction::Apply);
            $this->reach($approve1, $b);
            $cases[] = $case;
        }

        $one = count($this->statements(fn () => $this->rowCans($a, [$cases[0]])));
        $three = count($this->statements(fn () => $this->rowCans($a, $cases)));
        $cans = $this->rowCans($a, $cases);

        $this->assertSame($one, $three);
        $this->assertSame(array_map(fn (WorkflowCase $case) => $case->id, $cases), array_keys($cans));
        $this->assertSame(['pullBack' => true, 'reapply' => false, 'withdraw' => false, 'editDraft' => false, 'deleteDraft' => false], $cans[$cases[0]->id]);
        $this->assertSame(
            ['pullBack' => false, 'reapply' => false, 'withdraw' => false],
            $this->decider()->detailCanOf($this->requester($b), self::day(), $this->decider()->situationsOf($this->requester($b), self::day(), [$cases[0]])[$cases[0]->id]),
        );
    }

    /**
     * can の 403 の判定は、効く案件のフローだけで評価する: 一時保存も再申請待ちも無ければ、申請のノード(位置 1)の読み込みと業務モジュールの update のための
     * 結合が出ず、あれば出る。省いた結果は手で書いた can と照合する(本番の口で答えを作らない。現在のノードが 2 つの案件を含む)。
     */
    public function test_row_cans_judge_403_only_for_the_cases_that_need_it(): void
    {
        [$a, $b] = $this->seededUsers('u0001', 'u0002');
        $flow = $this->flow('wf_skips', $this->selection(SelectionConditionKind::User, $a->id));
        $waiting = $this->activeCase($flow, $a);
        [$apply, $approve1] = $this->caseNodes($waiting, 2);
        $this->process($apply, $a, WorkflowAction::Apply);
        $this->reach($approve1, $b);
        $reapply = $this->activeCase($flow, $a);
        [$reapplyApply, $reapplyApprove1] = $this->caseNodes($reapply, 2);
        $this->process($reapplyApply, $a, WorkflowAction::Apply);
        $this->process($reapplyApprove1, $b, WorkflowAction::SendBack, $reapplyApply);
        $this->reach($reapplyApply, $a);
        $draft = WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $flow->id, 'user_id' => $a->id]);
        $twoCurrent = $this->activeCase($flow, $a);
        [$twoApply, $twoApprove1, $twoApprove2] = $this->caseNodes($twoCurrent, 3);
        $this->process($twoApply, $a, WorkflowAction::Apply);
        $this->reach($twoApprove1, $b);
        $this->reach($twoApprove2, $a);
        $applyNodes = '/from "workflow_route_nodes" where .*"position" = /';
        $updateJoin = '/from "workflow_flows" inner join "workflow_contents" .* inner join "record_modules"/';

        $without = $this->statements(fn () => $this->rowCans($a, [$waiting]));
        $with = $this->statements(fn () => $this->rowCans($a, [$waiting, $reapply, $draft]));

        $this->assertSame([[], []], [preg_grep($applyNodes, $without), preg_grep($updateJoin, $without)]);
        $this->assertSame([1, 1], [count(preg_grep($applyNodes, $with) ?: []), count(preg_grep($updateJoin, $with) ?: [])]);
        $this->assertSame([true, true], [$this->rowCans($a, [$reapply])[$reapply->id]['reapply'], $this->rowCans($a, [$draft])[$draft->id]['editDraft']]);
        $this->assertSame([
            $waiting->id => ['pullBack' => true, 'reapply' => false, 'withdraw' => false, 'editDraft' => false, 'deleteDraft' => false],
            $reapply->id => ['pullBack' => false, 'reapply' => true, 'withdraw' => true, 'editDraft' => false, 'deleteDraft' => false],
            $draft->id => ['pullBack' => false, 'reapply' => false, 'withdraw' => false, 'editDraft' => true, 'deleteDraft' => true],
            $twoCurrent->id => ['pullBack' => true, 'reapply' => false, 'withdraw' => false, 'editDraft' => false, 'deleteDraft' => false],
        ], $this->rowCans($a, [$waiting, $reapply, $draft, $twoCurrent]));
    }

    private function decider(): CaseOperationDecider
    {
        return app(CaseOperationDecider::class);
    }

    private static function day(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::DATE);
    }

    private function requester(User $user): Requester
    {
        return new Requester($user->id, null);
    }

    private function permitsIn(WorkflowOperation $operation, User $user, WorkflowCase $case, ?int $nodeId): bool
    {
        $situation = $this->decider()->situationsOf($this->requester($user), self::day(), [$case])[$case->id];

        return $this->decider()->permitsIn($operation, $this->requester($user), self::day(), $situation, $nodeId);
    }

    /**
     * 業務モジュール(全員に 4 つのアクションを許す)をコンテンツにし、申請のノードの処理対象者の指定を渡したフロー(版 1 つのルートは申請 + 承認 2)。
     */
    private function flow(string $moduleCode, int $applicantSubjectGroupId): WorkflowFlow
    {
        $flow = WorkflowFlow::factory()->create([
            'workflow_content_id' => WorkflowContent::factory()->create(['record_module_id' => $this->recordModule($moduleCode)->id])->id,
        ]);
        $version = WorkflowFlowVersion::factory()->create(['workflow_flow_id' => $flow->id]);
        WorkflowRouteNode::factory()->create(['workflow_flow_version_id' => $version->id, 'position' => 1, 'authz_subject_group_id' => $applicantSubjectGroupId]);
        WorkflowRouteNode::factory()->create(['workflow_flow_version_id' => $version->id, 'position' => 2]);
        WorkflowRouteNode::factory()->create(['workflow_flow_version_id' => $version->id, 'position' => 3]);

        return $flow;
    }

    private function activeCase(WorkflowFlow $flow, User $applicant): WorkflowCase
    {
        return WorkflowCase::factory()->active()->create(['workflow_flow_id' => $flow->id, 'user_id' => $applicant->id]);
    }

    /**
     * @param  list<WorkflowCase>  $cases
     * @return array<int, array<string, bool>> 状況を読んで求めた行の can(situationsOf + rowCansOf)
     */
    private function rowCans(User $user, array $cases): array
    {
        return $this->decider()->rowCansOf($this->requester($user), self::day(), array_values($this->decider()->situationsOf($this->requester($user), self::day(), $cases)));
    }

    /**
     * @param  Closure(): mixed  $action
     * @return list<string> 処理の間に出た SQL
     */
    private function statements(Closure $action): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();

        return array_values(array_map(fn (array $query) => (string) $query['query'], DB::getQueryLog()));
    }
}
