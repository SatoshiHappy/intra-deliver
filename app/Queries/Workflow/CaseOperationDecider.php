<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use Closure;
use DateTimeInterface;

/**
 * 案件の操作の判定(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節の表と 10.5 の引戻しの 1 規則)
 * と画面の can(設計/ワークフロー/認可と一覧.md 3.2、画面/README.md 1.2・画面/申請と案件.md 5 節)。
 * 業務処理の判定と画面の can が食い違わないよう、どちらも状況を読んで役割を作り {@see CaseOperationTable} を引くこの 1 か所を通る。鍵を取らない。
 */
class CaseOperationDecider
{
    public function __construct(
        private CaseOperationTable $table,
        private SubjectGroupMembership $membership,
        private ApplicableFlows $applicableFlows,
        private UpdatableFlows $updatableFlows,
        private CaseTargetTerms $targetTerms,
        private CurrentNodes $currentNodes,
        private PullBackableNodes $pullBackableNodes,
        private ApplicantApprovalGuard $applicantGuard,
        private ProcessorRoles $roles,
        private CaseHolders $holders,
        private CaseConfirmations $confirmations,
        private BulkApprovals $bulkApprovals,
        private DraftRequesters $draftRequesters,
    ) {}

    /**
     * 一覧の行・関連する案件の行の can(画面/README.md 1.2 の WorkflowCaseRow)。
     * editDraft = 一時保存を開く(上書きの表 ∧ 申請できる人)、reapply = 表 ∧ 業務モジュールの update。
     *
     * @param  Closure(WorkflowOperation): bool  $permits  表(操作 → 許すか)
     * @return array{pullBack: bool, reapply: bool, withdraw: bool, editDraft: bool, deleteDraft: bool}
     */
    private function rowCanBy(Closure $permits, CaseRoles $roles): array
    {
        return [
            'pullBack' => $permits(WorkflowOperation::PullBack),
            'reapply' => $permits(WorkflowOperation::Reapply) && $roles->canUpdateRecord,
            'withdraw' => $permits(WorkflowOperation::Withdraw),
            'editDraft' => $permits(WorkflowOperation::OverwriteDraft) && $roles->canApply,
            'deleteDraft' => $permits(WorkflowOperation::DeleteDraft),
        ];
    }

    /**
     * 詳細の案件に 1 つの can(ルートの深さ/画面/案件の側の画面.md 7 節。ノードごとの承認・承認終了・否認・差戻しは {@see CaseNodeActions})。
     * reapply = 表 ∧ 業務モジュールの update。
     *
     * @param  Closure(WorkflowOperation): bool  $permits  表(操作 → 許すか)
     * @return array{pullBack: bool, reapply: bool, withdraw: bool}
     */
    private function detailCanBy(Closure $permits, CaseRoles $roles): array
    {
        return [
            'pullBack' => $permits(WorkflowOperation::PullBack),
            'reapply' => $permits(WorkflowOperation::Reapply) && $roles->canUpdateRecord,
            'withdraw' => $permits(WorkflowOperation::Withdraw),
        ];
    }

    /**
     * 判定を求める者が d にそのフローの業務モジュールの update を許されるか(役割の canUpdateRecord と同じ判定。再申請の 403 は入口・画面の GET・業務処理が
     * この口を通る)。
     */
    public function isUpdatable(Requester $requester, DateTimeInterface $date, int $flowId): bool
    {
        return $this->updatableFlows->idsOf($requester, $date, [$flowId]) !== [];
    }

    /**
     * 読み込み済みの状況({@see self::situationsOf()} で 1 回読んだもの)で、表がその操作を、操作するノードについて状況の処理者 P として許すか。
     * 操作するノードが現在のノードでなければ、ノードの種類と状態は無いとして表を引く。引戻しできる処理者は、操作するノードを引戻せるか。
     *
     * @param  int|null  $nodeId  操作するノード(承認・承認終了・否認・差戻し・引戻しは入力の node_id、再申請・取止めは申請のノード。ほかは null)
     * @param  bool  $withoutOwnRow  ノードの処理対象者で P の本人の行を問わない({@see ProcessorRoles::of()})
     * @param  bool  $canOperate  判定を求める者が案件のフローの operate を許されるか(呼び手が {@see FlowPermissions} で求める)
     */
    public function permitsIn(
        WorkflowOperation $operation,
        Requester $requester,
        DateTimeInterface $date,
        CaseSituation $situation,
        ?int $nodeId,
        bool $withoutOwnRow = false,
        bool $canOperate = false,
    ): bool {
        $node = $nodeId === null ? null : $situation->currentNode($nodeId);
        $isPullBackActor = $nodeId !== null && $situation->canPullBack($nodeId);
        $userId = $this->membership->effectiveUserId($requester, $date);
        $roles = $this->roles->of($userId, $situation, $node, $isPullBackActor, false, false, $withoutOwnRow, $canOperate);

        return $this->table->permits($operation, $situation->case->state, $node?->kind, $node?->state, $roles);
    }

    /**
     * 一括承認で選べる組(設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節)。行の can.bulkApprove と一括承認の処理の時の組は
     * どちらもここを通る(判定は {@see BulkApprovals})。
     *
     * @param  list<CaseSituation>  $situations  自分を処理者として読んだ状況
     * @return array<int, BulkApproval|null> 案件の id → 組(選べなければ null)
     */
    public function bulkApprovalsOf(Requester $requester, DateTimeInterface $date, array $situations): array
    {
        return $this->bulkApprovals->of($this->membership->effectiveUserId($requester, $date), $date, $situations);
    }

    /**
     * 一括確認で選べるか(行の can.confirm = 確認の前提。同じ文書の 10.7 節)。
     */
    public function canConfirm(Requester $requester, DateTimeInterface $date, CaseSituation $situation): bool
    {
        return $this->permitsIn(WorkflowOperation::Confirm, $requester, $date, $situation, null);
    }

    /**
     * 案件ごとの状況。問い合わせは現在のノード({@see CurrentNodes})・処理対象者の項({@see CaseTargetTerms})・引戻せるノード({@see PullBackableNodes})・
     * 自分が確認対象者の開いている確認({@see CaseConfirmations})で、
     * 案件の数に依らない。判定を求める者が d に有効でなければ、処理対象者の項と引戻せるノードは読まない(役割がどれも偽なので要らない)。
     *
     * @param  list<WorkflowCase>  $cases
     * @param  int|null  $processorUserId  処理者 P(null = 自分。業務処理が代理元・一時保存の持ち主を確かめてから渡す)
     * @return array<int, CaseSituation> 案件の id → 状況
     */
    public function situationsOf(Requester $requester, DateTimeInterface $date, array $cases, ?int $processorUserId = null): array
    {
        if ($cases === []) {
            return [];
        }

        $userId = $this->membership->effectiveUserId($requester, $date);
        $processor = $userId === null ? null : $processorUserId ?? $userId;
        $caseIds = array_map(fn (WorkflowCase $case) => $case->id, $cases);
        $currentNodes = $this->currentNodes->of($caseIds);
        $nodeIds = array_merge([], ...array_map(fn (array $nodes) => array_map(fn (CurrentNode $node) => $node->id, $nodes), array_values($currentNodes)));
        $targetNodeIds = $nodeIds === [] || $userId === null ? [] : $this->targetTerms->targetNodeIds($nodeIds, $userId, $date);
        $pullBackNodes = $processor === null ? [] : $this->pullBackableNodes->of($caseIds, $processor);
        $holders = $this->holders->of($this->heldNodeIds($currentNodes));
        $applicants = $this->applicantsOfPreventingPullBacks($cases, $pullBackNodes);
        $confirmNodeIds = $userId === null ? [] : $this->confirmations->openTargetNodeIds($caseIds, $userId);
        $situations = [];

        foreach ($cases as $case) {
            $nodes = $currentNodes[$case->id];
            $ownTargets = array_values(array_filter(array_map(fn (CurrentNode $node) => $node->id, $nodes), fn (int $id) => in_array($id, $targetNodeIds, true)));
            $pullBacks = [];

            foreach ($pullBackNodes[$case->id] ?? [] as $node) {
                $reason = $this->pullBackBlockedReason($case, $node, $processor, $applicants[$case->id] ?? []);
                $pullBacks[$node->id] = ['name' => $node->displayName(), 'blockedReason' => $reason];
            }

            $caseNodeIds = array_map(fn (CurrentNode $node) => $node->id, $nodes);
            $heldBy = array_intersect_key($holders, array_flip($caseNodeIds));
            $confirms = $confirmNodeIds[$case->id] ?? [];
            $situation = new CaseSituation($case, $nodes, $ownTargets, $pullBacks, $processor, $heldBy, $confirms);
            $situations[$case->id] = $situation;
        }

        return $situations;
    }

    /**
     * 保留中の現在のノードの id(保留者側の判定に最新の保留の行を引くノード)。
     *
     * @param  array<int, list<CurrentNode>>  $currentNodes  案件の id → 現在のノード
     * @return list<int>
     */
    private function heldNodeIds(array $currentNodes): array
    {
        $nodes = array_merge([], ...array_values($currentNodes));
        $held = array_filter($nodes, fn (CurrentNode $node) => $node->state === WorkflowNodeState::Hold);

        return array_values(array_map(fn (CurrentNode $node) => $node->id, $held));
    }

    /**
     * 状況ごとの行の can。403 の判定は、効く案件(一時保存 = 申請できる人、再申請待ち = update)のフローだけで評価する(ほかは表が偽なので結果は同じ)。
     * ノードの操作は、現在のノードのどれかで許されれば真。代理元の一時保存の行は持ち主が判定する人({@see DraftRequesters})。
     *
     * @param  list<CaseSituation>  $situations
     * @return array<int, array{pullBack: bool, reapply: bool, withdraw: bool, editDraft: bool, deleteDraft: bool}> 案件の id → can
     */
    public function rowCansOf(Requester $requester, DateTimeInterface $date, array $situations): array
    {
        $cans = [];
        $asOwners = $this->draftRequesters->of($requester, $date, $situations);

        foreach ($this->canInputs($requester, $date, $situations) as $caseId => [$situation, $userId, $canApply, $canUpdate]) {
            [$situation, $canApply] = $asOwners[$caseId] ?? [$situation, $canApply];
            $cans[$caseId] = $this->rowCanBy($this->anyNode($situation, $userId), $this->roles->of($userId, $situation, null, false, $canApply, $canUpdate));
        }

        return $cans;
    }

    /**
     * 案件の詳細の案件に 1 つの can(403 の判定の省き方とノードの読み方は {@see self::rowCansOf()} と同じ)。
     *
     * @return array{pullBack: bool, reapply: bool, withdraw: bool}
     */
    public function detailCanOf(Requester $requester, DateTimeInterface $date, CaseSituation $situation): array
    {
        [, $userId, $canApply, $canUpdate] = $this->canInputs($requester, $date, [$situation])[$situation->case->id];

        return $this->detailCanBy($this->anyNode($situation, $userId), $this->roles->of($userId, $situation, null, false, $canApply, $canUpdate));
    }

    /**
     * 引戻せるノードに申請者承認防止がオンのものを持つ案件だけの、申請者として扱う人(代理の表を引くので、要る案件だけを 1 回で引く)。
     *
     * @param  list<WorkflowCase>  $cases
     * @param  array<int, list<WorkflowCaseNode>>  $pullBackNodes  案件の id → 引戻せるノード
     * @return array<int, non-empty-list<int>> 案件の id → ユーザーの id
     */
    private function applicantsOfPreventingPullBacks(array $cases, array $pullBackNodes): array
    {
        $preventing = fn (WorkflowCase $case) => array_filter(
            $pullBackNodes[$case->id] ?? [],
            fn (WorkflowCaseNode $node) => $node->prevents_applicant_approval,
        ) !== [];
        $needed = array_values(array_filter($cases, $preventing));

        return $needed === [] ? [] : $this->applicantGuard->applicantUserIdsByCase($needed);
    }

    /**
     * 引戻すとノードに引戻す人(処理者 P)だけで到達し直すので、業務処理の引戻しと同じ式(設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.5)で文言を作る。
     *
     * @param  list<int>  $applicantUserIds  申請者として扱う人(防止がオンの引戻せるノードを持つ案件だけ。ほかは空)
     */
    private function pullBackBlockedReason(
        WorkflowCase $case,
        WorkflowCaseNode $node,
        ?int $processorUserId,
        array $applicantUserIds,
    ): ?string {
        if ($processorUserId === null || ! $node->prevents_applicant_approval) {
            return null;
        }

        $blocked = $this->applicantGuard->onlyApplicants(true, [$processorUserId], $applicantUserIds);

        return $blocked ? ApplicantApprovalGuard::onlyApplicantTargetsMessage($node->displayName(), [$processorUserId], $case->user_id) : null;
    }

    /**
     * can の入力(状況・d に有効なユーザー・canApply・canUpdateRecord)。canApply は一時保存の案件のフローだけ、canUpdateRecord は再申請待ちの案件のフローだけで評価し、ほかは偽。
     *
     * @param  list<CaseSituation>  $situations
     * @return array<int, array{CaseSituation, int|null, bool, bool}> 案件の id → 入力
     */
    private function canInputs(Requester $requester, DateTimeInterface $date, array $situations): array
    {
        $userId = $this->membership->effectiveUserId($requester, $date);
        $flowIdsOf = fn (Closure $isNeeded) => array_values(array_unique(array_map(fn (CaseSituation $situation) => $situation->case->workflow_flow_id, array_filter($situations, $isNeeded))));
        $applicable = $userId === null ? [] : $this->applicableFlows->applicableIds($requester, $date, $flowIdsOf(fn (CaseSituation $situation) => $situation->case->state === WorkflowCaseState::Draft));
        $updatable = $this->updatableFlows->idsOf($requester, $date, $flowIdsOf(fn (CaseSituation $situation) => $situation->labelState() === WorkflowNodeState::Reapply));
        $inputs = [];

        foreach ($situations as $situation) {
            $flowId = $situation->case->workflow_flow_id;
            $inputs[$situation->case->id] = [$situation, $userId, in_array($flowId, $applicable, true), in_array($flowId, $updatable, true)];
        }

        return $inputs;
    }

    /**
     * can の表: ノードを問わない操作(ノード = null)と、現在のノードごとの表の OR(どれかのノードで許されれば真 = 画面/案件の側の画面.md 7 節)。引戻しは
     * 引戻せるノードが 1 つ以上あれば真(認可と一覧.md 2 節)。403 の判定(canApply・canUpdateRecord)は呼び手が別に AND する。
     *
     * @return Closure(WorkflowOperation): bool
     */
    private function anyNode(CaseSituation $situation, ?int $userId): Closure
    {
        return function (WorkflowOperation $operation) use ($situation, $userId): bool {
            foreach ([null, ...$situation->currentNodes] as $node) {
                $roles = $this->roles->of($userId, $situation, $node, $situation->pullBackNodes !== [], false, false);

                if ($this->table->permits($operation, $situation->case->state, $node?->kind, $node?->state, $roles)) {
                    return true;
                }
            }

            return false;
        };
    }
}
