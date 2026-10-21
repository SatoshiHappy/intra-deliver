<?php

namespace Tests\Unit\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowOperation;
use App\Queries\Workflow\CaseOperationTable;
use App\Queries\Workflow\CaseRoles;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Queries\Workflow\CaseOperationDeciderTest;

/**
 * 状態 × 操作の表(docs の 設計/ワークフロー/案件の操作/操作.md 3.2)の全組(同時実行と検査.md 7 節)。DB を使わずに確かめる。
 * 期待値は 操作.md 3.2 の表から書いた(実装から作らない)。申請できる人(403)は表の外なので、「誰が」が申請できる人だけの行(一時保存の新規・申請の新規)は、
 * 表では案件が無ければ誰でも通る(403 は can と業務処理が別に見る)。役割ごとの本番の口の判定は {@see CaseOperationDeciderTest}。
 * 保留・保留解除・振替・確認の行の期待値は 設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節から書いた(確認 = 未完了で確認対象者)。
 */
final class CaseOperationTableTest extends TestCase
{
    /** 案件が無いときにいる役割(申請者・処理者・参照者は成り立たない) */
    private const WITHOUT_CASE = ['canApplyPerson', 'cannotApplyUser', 'unrelated', 'guest'];

    /** 承認待ちの承認のノードを処理できる状況 */
    private const APPROVABLE = ['approve1Waiting' => ['currentTarget'], 'approve2Waiting' => ['currentTarget'], 'afterPullBack' => ['currentTarget'], 'sentBackToApprove1' => ['currentTarget']];

    /** 承認のノードを処理できる状況(承認待ちの処理対象者と、保留中の保留者側 = 設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節) */
    private const HANDLES_APPROVAL = [...self::APPROVABLE, 'held' => ['holdSide']];

    /**
     * 操作 => 状況 => 表が通す役割(設計/ワークフロー/案件の操作/操作.md 3.2 の表から書いた。載っていない組は拒む)。
     */
    private const PERMITTED = [
        'save_draft' => ['none' => self::WITHOUT_CASE],
        'overwrite_draft' => ['draft' => ['applicant']],
        'delete_draft' => ['draft' => ['applicant']],
        'apply' => ['none' => self::WITHOUT_CASE, 'draft' => ['applicant']],
        'approve' => self::HANDLES_APPROVAL,
        'approve_early' => self::HANDLES_APPROVAL,
        'reject' => self::HANDLES_APPROVAL,
        'send_back' => self::HANDLES_APPROVAL,
        'pull_back' => ['approve1Waiting' => ['applicant'], 'approve2Waiting' => ['lastActor'], 'reapplyAfterSendBack' => ['lastActor'], 'sentBackToApprove1' => ['lastActor']],
        'reapply' => ['reapplyAfterSendBack' => ['applicant']],
        'withdraw' => ['reapplyAfterSendBack' => ['applicant']],
        'hold' => self::APPROVABLE,
        'release' => ['held' => ['holdSide']],
        'transfer' => self::HANDLES_APPROVAL,
        'confirm' => ['confirmOpen' => ['confirmTarget']],
    ];

    public function test_the_table_permits_exactly_the_rows_of_the_contract(): void
    {
        $actual = [];

        foreach (WorkflowOperation::cases() as $operation) {
            foreach (self::situations() as $situation => [$state, $kind, $nodeState, $roles]) {
                $permitted = array_keys(array_filter($roles, fn (CaseRoles $caseRoles) => $this->table()->permits($operation, $state, $kind, $nodeState, $caseRoles)));
                $actual[$operation->value] = [...$actual[$operation->value] ?? [], ...($permitted === [] ? [] : [$situation => $permitted])];
            }
        }

        $this->assertSame(self::PERMITTED, array_filter($actual));
    }

    /**
     * 状況 => [案件の状態, 現在のノードの種類, 現在のノードの状態, 役割 => 役割の値]。役割の値は、その状況の具体の人(テストの組み立てと同じ流れ)で書く。
     * 成り立たない役割(案件が無いときの申請者、ほかの役割と同じ人になるもの)は載せない(該当なし)。申請できる人は案件に関わらない人で申請できる人。
     *
     * @return array<string, array{WorkflowCaseState|null, WorkflowNodeKind|null, WorkflowNodeState|null, array<string, CaseRoles>}>
     */
    private static function situations(): array
    {
        $none = new CaseRoles(false, false, false, false, true);
        $outsiders = ['canApplyPerson' => new CaseRoles(false, false, false, true, true), 'cannotApplyUser' => $none];
        $others = ['referrer' => $none, 'unrelated' => $none, 'guest' => new CaseRoles(false, false, false, false, false)];
        $applicant = fn (bool $isCurrentTarget = false, bool $isPullBackActor = false) => new CaseRoles(true, $isCurrentTarget, $isPullBackActor, true, true);
        $target = new CaseRoles(false, true, false, false, true);
        $last = new CaseRoles(false, false, true, false, true);
        $holdSide = new CaseRoles(false, true, false, false, true, isHoldSide: true);
        $confirmTarget = new CaseRoles(false, false, false, false, true, isConfirmTarget: true);
        $active = fn (WorkflowNodeKind $kind, WorkflowNodeState $state, array $roles) => [WorkflowCaseState::Active, $kind, $state, [...$outsiders, ...$roles, ...$others]];
        $completed = fn (array $roles) => [WorkflowCaseState::Completed, null, null, [...$outsiders, ...$roles, ...$others]];

        return [
            // 案件が無い
            'none' => [null, null, null, [...$outsiders, 'unrelated' => $none, 'guest' => $others['guest']]],
            // 一時保存
            'draft' => [WorkflowCaseState::Draft, null, null, [...$outsiders, 'applicant' => $applicant(), ...$others]],
            // 申請 A → 承認 1 が承認待ち(処理対象者 B)。A が直前の処理者
            'approve1Waiting' => $active(WorkflowNodeKind::Approve, WorkflowNodeState::Waiting, ['applicant' => $applicant(isPullBackActor: true), 'currentTarget' => $target]),
            // 申請 A → 承認 1 を B が承認 → 承認 2 が承認待ち(C)
            'approve2Waiting' => $active(WorkflowNodeKind::Approve, WorkflowNodeState::Waiting, ['applicant' => $applicant(), 'currentTarget' => $target, 'lastActor' => $last]),
            // 申請 A → 承認 1 を B → 承認 2 の C が申請のノードへ差戻し(再申請待ち。処理対象者 = A)。B は以前の処理者
            'reapplyAfterSendBack' => $active(WorkflowNodeKind::Apply, WorkflowNodeState::Reapply, ['applicant' => $applicant(isCurrentTarget: true), 'lastActor' => $last, 'earlierActor' => $none]),
            // 申請 A → 承認 1 を B → B が引戻し(承認 1 が承認待ち。処理対象者 = B。最新の行は引戻しなので直前の処理者はいない)
            'afterPullBack' => $active(WorkflowNodeKind::Approve, WorkflowNodeState::Waiting, ['applicant' => $applicant(), 'currentTarget' => $target]),
            // 完了 4 つ(承認 = 最後の承認の B が最新の行を持つ。承認終了・否認は直前の処理者にならない種別。取止めは申請者 A が最新の行)
            'completedApproved' => $completed([
                'applicant' => $applicant(), 'lastActor' => $last, 'confirmTarget' => $confirmTarget,
            ]),
            'completedApprovedEarly' => $completed(['applicant' => $applicant(), 'lastActor' => $none]),
            'completedRejected' => $completed(['applicant' => $applicant(), 'lastActor' => $none]),
            'completedWithdrawn' => $completed(['applicant' => $applicant(), 'earlierActor' => $none]),
            // 申請 A → 承認 1 を B → 承認 2 の C が承認 1 へ差戻し(承認 1 が承認待ち。処理対象者 = B)
            'sentBackToApprove1' => $active(WorkflowNodeKind::Approve, WorkflowNodeState::Waiting, ['applicant' => $applicant(), 'currentTarget' => $target, 'lastActor' => $last]),
            // 承認 1 の処理対象者 B・C のうち B が保留(B = 保留者側。C = 保留していない処理対象者)
            'held' => $active(WorkflowNodeKind::Approve, WorkflowNodeState::Hold, [
                'applicant' => $applicant(), 'holdSide' => $holdSide, 'heldTarget' => $target,
            ]),
            // 承認 1 を B が承認して確認が開き(確認対象者 = 承認 1 の確認する人)、承認 2 が承認待ち
            'confirmOpen' => $active(WorkflowNodeKind::Approve, WorkflowNodeState::Waiting, [
                'applicant' => $applicant(), 'confirmTarget' => $confirmTarget,
            ]),
        ];
    }

    private function table(): CaseOperationTable
    {
        return new CaseOperationTable;
    }
}
