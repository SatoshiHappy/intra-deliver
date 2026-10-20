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
use App\Queries\Workflow\CaseSituation;
use Carbon\CarbonImmutable;
use Closure;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 業務処理が使う判定の口(docs の 設計/ワークフロー/案件の操作/操作.md 3.1 の 2): permitsIn(状況から表だけを判定する)と isUpdatable(再申請の 403)、
 * 役割ごとの本番の口(permitsIn・rowCansOf・detailCanOf・isUpdatable)の判定。期待は 操作.md 3.2 の表から手で書いた(実装から作らない)。案件の行はテストで作り、
 * 初期データのユーザーを使う(CaseOperationDeciderTest と同じ組み立て。
 * そちらのクラスの大きさの上限のため別のファイルにした)。
 */
final class CaseOperationDeciderPermitsInTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use BuildsWorkflowCases;
    use UsesSeededRecords;

    /** 承認待ちの承認のノードの処理対象者に表が許す操作 */
    private const APPROVING = ['approve', 'approve_early', 'reject', 'send_back', 'hold', 'transfer'];

    /**
     * 一時保存・承認 1 の承認待ち・承認 2 の承認待ち・再申請待ち・完了・引戻しの後の案件で、5 人(ゲストを含む)× 引戻しを除く操作 10 の permitsIn が
     * 表の期待と一致する(操作するノード = 案件のただ 1 つの現在のノード、無ければ null。引戻しは下の別のテストで、ノードごとに確かめる)。
     */
    public function test_permits_in_follows_the_table_for_each_case_and_person(): void
    {
        [$a, $b, $c, $d] = $this->seededUsers('u0001', 'u0002', 'u0003', 'u0004');
        $flow = $this->flow('wf_permits_in', $this->selection(SelectionConditionKind::User, $a->id));
        $pulledBack = $this->waitingAt($flow, $a, 1, $b, $b);
        $this->process($this->nodesOf($pulledBack)[1], $b, WorkflowAction::PullBack);
        $cases = [
            'draft' => WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $flow->id, 'user_id' => $a->id]),
            'approve1Waiting' => $this->waitingAt($flow, $a, 1, $b),
            'approve2Waiting' => $this->waitingAt($flow, $a, 2, $c, $b),
            'reapplyWaiting' => $this->waitingAt($flow, $a, 0, $a),
            'completed' => WorkflowCase::factory()->completed()->create(['workflow_flow_id' => $flow->id, 'user_id' => $a->id]),
            'afterPullBack' => $pulledBack,
        ];
        $people = ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'guest' => null];
        $nobody = ['a' => [], 'b' => [], 'c' => [], 'd' => [], 'guest' => []];

        $this->assertSame([
            // 申請者 a の一時保存: 上書き・削除・申請は申請者だけ(一時保存の新規は案件が無いときだけ)
            'draft' => [...$nobody, 'a' => ['overwrite_draft', 'delete_draft', 'apply']],
            // 承認待ち: 現在のノードの処理対象者だけが承認・承認終了・否認・差戻し
            'approve1Waiting' => [...$nobody, 'b' => self::APPROVING],
            'approve2Waiting' => [...$nobody, 'c' => self::APPROVING],
            // 再申請待ち: 申請のノードの処理対象者(= 申請者)だけが再申請・取止め
            'reapplyWaiting' => [...$nobody, 'a' => ['reapply', 'withdraw']],
            'completed' => $nobody,
            // 引戻しの後: 引戻した b が承認 1 の処理対象者
            'afterPullBack' => [...$nobody, 'b' => self::APPROVING],
        ], array_map(fn (WorkflowCase $case) => array_map(fn (?User $user) => $this->permittedIn($case, $user), $people), $cases));
    }

    /**
     * 1 つの案件と人について、permitsIn が許す引戻しを除く操作(操作するノード = 案件のただ 1 つの現在のノード、無ければ null)。
     *
     * @return list<string> 操作の値(列挙の順)
     */
    private function permittedIn(WorkflowCase $case, ?User $user): array
    {
        $requester = new Requester($user?->id, null);
        $situation = $this->decider()->situationsOf($requester, $this->day(), [$case])[$case->id];
        $this->assertLessThanOrEqual(1, count($situation->currentNodes));
        $nodeId = ($situation->currentNodes[0] ?? null)?->id;
        $permitted = array_filter(
            WorkflowOperation::cases(),
            fn (WorkflowOperation $operation) => $operation !== WorkflowOperation::PullBack && $this->decider()->permitsIn($operation, $requester, $this->day(), $situation, $nodeId),
        );

        return array_values(array_map(fn (WorkflowOperation $operation) => $operation->value, $permitted));
    }

    /**
     * 役割(案件の操作の README.md 1.3)は、その役割で真になる操作を表(操作.md 3.2)が許す場面で本番の口で確かめ、その役割の人だけが真(人の並び = a・b・c・d・
     * ゲスト・d に有効でない人)。案件 = a の一時保存・承認 2 の承認待ち(承認 1 を b が承認し、b の処理対象者の行が承認 1 に残る)・再申請待ち。
     */
    public function test_each_role_is_true_only_for_its_person_where_the_table_permits_it(): void
    {
        [$a, $b, $c, $d, $retired] = $this->seededUsers('u0001', 'u0002', 'u0003', 'u0004', 'u0014');
        $flow = $this->flow('wf_roles', $this->selection(SelectionConditionKind::User, $a->id));
        $draft = $this->draftOf($flow, $a);
        $waiting = $this->waitingAt($flow, $a, 2, $c, $b);
        [$apply, $approve1, $approve2] = $this->nodesOf($waiting);
        $this->target($approve1, $b);
        $reapply = $this->sentBackToApply($flow, $a, $b);
        $reapplyApply = $this->nodesOf($reapply)[0];
        $all = fn (Closure $judge) => array_map(fn (?User $user) => $judge(new Requester($user?->id, null)), [$a, $b, $c, $d, null, $retired]);
        $in = fn (WorkflowOperation $operation, WorkflowCase $case, ?int $nodeId) => $all(fn (Requester $requester) => $this->decider()->permitsIn($operation, $requester, $this->day(), $this->situation($requester, $case), $nodeId));
        $onlyA = [true, false, false, false, false, false];

        // 申請者: 一時保存の上書き・削除と、行の一時保存の削除
        $this->assertSame([$onlyA, $onlyA, $onlyA], [$in(WorkflowOperation::OverwriteDraft, $draft, null), $in(WorkflowOperation::DeleteDraft, $draft, null), $all(fn (Requester $requester) => $this->rowCan($requester, $draft)['deleteDraft'])]);
        // ノードの処理対象者: 承認 2 の承認は c だけ(承認 1 に行を持つ b は当たらない)
        $this->assertSame([false, false, true, false, false, false], $in(WorkflowOperation::Approve, $waiting, $approve2->id));
        // 申請のノードの処理対象者(= 申請者): 再申請待ちの申請のノードの取止め・再申請
        $this->assertSame([$onlyA, $onlyA], [$in(WorkflowOperation::Withdraw, $reapply, $reapplyApply->id), $in(WorkflowOperation::Reapply, $reapply, $reapplyApply->id)]);
        // 引戻しできる処理者: 承認 1 の最新の行を持つ b だけが承認 1 を引戻せる(申請のノードは承認 1 の行が後で変えた。承認 2 は誰も処理していない)
        $this->assertSame(
            [array_fill(0, 6, false), [false, true, false, false, false, false], array_fill(0, 6, false)],
            [$in(WorkflowOperation::PullBack, $waiting, $apply->id), $in(WorkflowOperation::PullBack, $waiting, $approve1->id), $in(WorkflowOperation::PullBack, $waiting, $approve2->id)],
        );
        // 申請できる人: 一時保存を開ける(editDraft)のは申請できる a だけ。b〜d は自分の一時保存を消せるが開けない(d に有効でない人は消せもしない)
        $this->assertSame($onlyA, $all(fn (Requester $requester) => $this->rowCan($requester, $draft)['editDraft']));
        $this->assertSame(
            [[false, true], [false, true], [false, true], [false, false]],
            array_map(fn (User $user) => array_values(array_intersect_key($this->rowCan(new Requester($user->id, null), $this->draftOf($flow, $user)), ['editDraft' => 0, 'deleteDraft' => 0])), [$b, $c, $d, $retired]),
        );
        // 更新できる人: update を許される d に有効な人(申請者でなくても)。再申請の can はそのうち再申請待ちの処理対象者の a だけ
        $this->assertSame([true, true, true, true, false, false], $all(fn (Requester $requester) => $this->decider()->isUpdatable($requester, $this->day(), $flow->id)));
        $this->assertSame($onlyA, $all(fn (Requester $requester) => $this->decider()->detailCanOf($requester, $this->day(), $this->situation($requester, $reapply))['reapply']));
    }

    /**
     * 申請できる人と更新できる人(403 の判定)は業務モジュールのポリシーに従う: create・update を拒むと、a は自分の一時保存を消せるが開けず(editDraft)、
     * 再申請待ちの案件を取止めできるが再申請できない(reapply・isUpdatable)。表(permitsIn)は 403 を含まないので変わらない。
     */
    public function test_the_403_roles_follow_the_module_policy(): void
    {
        [$a, $b] = $this->seededUsers('u0001', 'u0002');
        $flow = $this->flow('wf_policy', $this->selection(SelectionConditionKind::User, $a->id));
        $draft = $this->draftOf($flow, $a);
        $reapply = $this->sentBackToApply($flow, $a, $b);
        $requester = new Requester($a->id, null);
        $judge = fn () => [
            $this->rowCan($requester, $draft),
            $this->decider()->detailCanOf($requester, $this->day(), $this->situation($requester, $reapply)),
            $this->decider()->isUpdatable($requester, $this->day(), $flow->id),
            $this->decider()->permitsIn(WorkflowOperation::OverwriteDraft, $requester, $this->day(), $this->situation($requester, $draft), null),
        ];
        $draftCan = fn (bool $editDraft) => ['pullBack' => false, 'reapply' => false, 'withdraw' => false, 'editDraft' => $editDraft, 'deleteDraft' => true];
        $this->assertSame([$draftCan(true), ['pullBack' => false, 'reapply' => true, 'withdraw' => true], true, true], $judge());

        $this->deny('wf_policy', RecordModuleAction::Create);
        $this->deny('wf_policy', RecordModuleAction::Update);

        $this->assertSame([$draftCan(false), ['pullBack' => false, 'reapply' => false, 'withdraw' => true], false, true], $judge());
    }

    /**
     * 引戻しの permitsIn は、渡したノードの最新の履歴の行が自分の引戻せる種別で、その後の行が X の子孫を変えていないときだけ真(設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.5 の 1 規則・8 節の
     * 引戻しの行)。手助けの行は node_changes が空なので、期待は 10.5 の 2 の「X の子孫」で書いた(案件は申請 → 承認 1 → 承認 2 の一本道)。
     */
    public function test_pull_back_is_permitted_only_for_the_node_whose_latest_row_the_person_may_pull_back(): void
    {
        [$a, $b, $c] = $this->seededUsers('u0001', 'u0002', 'u0003');
        $flow = $this->flow('wf_pull_back_in', $this->selection(SelectionConditionKind::User, $a->id));
        $atFirst = $this->waitingAt($flow, $a, 1, $b);
        $atSecond = $this->waitingAt($flow, $a, 2, $c, $b);
        $pulledBack = $this->waitingAt($flow, $a, 1, $b, $b);
        $this->process($this->nodesOf($pulledBack)[1], $b, WorkflowAction::PullBack);
        [$apply1, $approve1] = $this->nodesOf($atFirst);
        [$apply2, $approve2, $approve3] = $this->nodesOf($atSecond);
        [$apply3, $approve4] = $this->nodesOf($pulledBack);
        $judge = fn (WorkflowCase $case, ?User $user, WorkflowCaseNode $node) => $this->decider()->permitsIn(WorkflowOperation::PullBack, new Requester($user?->id, null), $this->day(), $this->decider()->situationsOf(new Requester($user?->id, null), $this->day(), [$case])[$case->id], $node->id);

        $this->assertSame(
            [true, false, false, false, true, false, false, false, false, false, false],
            [
                $judge($atFirst, $a, $apply1), $judge($atFirst, $a, $approve1), $judge($atFirst, $b, $apply1), $judge($atFirst, null, $apply1),
                $judge($atSecond, $b, $approve2), $judge($atSecond, $b, $apply2), $judge($atSecond, $a, $apply2), $judge($atSecond, $c, $approve3),
                $judge($pulledBack, $b, $approve4), $judge($pulledBack, $a, $apply3), $judge($pulledBack, $b, $apply3),
            ],
        );
    }

    /**
     * isUpdatable(再申請の 403)は、d に有効で業務モジュールの update を許される人だけ真(設計/ワークフロー/案件の操作/操作.md 3.1 の 2。申請者でなくても同じ。ゲスト・d に有効でない人は偽、
     * update を拒むと偽)。
     */
    public function test_is_updatable_follows_the_module_policy_and_the_effective_user(): void
    {
        [$a, $b, $retired] = $this->seededUsers('u0001', 'u0002', 'u0014');
        $flow = $this->flow('wf_updatable', $this->selection(SelectionConditionKind::User, $a->id));
        $judge = fn (?User $user) => $this->decider()->isUpdatable(new Requester($user?->id, null), $this->day(), $flow->id);

        $this->assertSame([true, true, false, false], [$judge($a), $judge($b), $judge(null), $judge($retired)]);

        $this->deny('wf_updatable', RecordModuleAction::Update);

        $this->assertSame([false, false], [$judge($a), $judge($b)]);
    }

    /**
     * 申請のノードを処理済み(申請)にし、位置 $position のノードを $target の到達にした未完了の案件($position = 0 は申請のノード = 再申請待ち)。
     * $approver を渡すと、位置 2 のノードをその人の承認で処理済みにしてから到達させる。
     */
    private function waitingAt(WorkflowFlow $flow, User $applicant, int $position, User $target, ?User $approver = null): WorkflowCase
    {
        $case = WorkflowCase::factory()->active()->create(['workflow_flow_id' => $flow->id, 'user_id' => $applicant->id]);
        $nodes = $this->caseNodes($case, 3);
        $this->process($nodes[0], $applicant, WorkflowAction::Apply);

        if ($approver !== null) {
            $this->process($nodes[1], $approver, WorkflowAction::Approve);
        }

        $this->reach($nodes[$position], $target);

        return $case;
    }

    /**
     * 申請のノードを処理済み(申請)にし、承認 1 を $approver の差戻しで処理済みにして、申請のノードを再申請待ち(処理対象者 = 申請者)にした案件。
     */
    private function sentBackToApply(WorkflowFlow $flow, User $applicant, User $approver): WorkflowCase
    {
        $case = $this->waitingAt($flow, $applicant, 1, $approver);
        [$apply, $approve1] = $this->nodesOf($case);
        $this->process($approve1, $approver, WorkflowAction::SendBack, $apply);
        $this->reach($apply, $applicant);

        return $case;
    }

    private function draftOf(WorkflowFlow $flow, User $applicant): WorkflowCase
    {
        return WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $flow->id, 'user_id' => $applicant->id]);
    }

    private function situation(Requester $requester, WorkflowCase $case): CaseSituation
    {
        return $this->decider()->situationsOf($requester, $this->day(), [$case])[$case->id];
    }

    /**
     * @return array{pullBack: bool, reapply: bool, withdraw: bool, editDraft: bool, deleteDraft: bool} 1 つの案件の行の can
     */
    private function rowCan(Requester $requester, WorkflowCase $case): array
    {
        return $this->decider()->rowCansOf($requester, $this->day(), [$this->situation($requester, $case)])[$case->id];
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

    private function decider(): CaseOperationDecider
    {
        return app(CaseOperationDecider::class);
    }

    private function day(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-13');
    }
}
