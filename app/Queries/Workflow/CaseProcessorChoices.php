<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowOperation;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use App\Support\AccessContext;

/**
 * 詳細のだれとして処理するか(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節・4.5 節、
 * 画面/案件の画面.md の 4 節・5 節): ノードの操作の処理担当者の選択肢({@see CaseNodeActions})、引戻せるノードとその処理者
 * (h の処理者。自分なら null)、取止め・再申請で送る処理者 casePrincipal、案件に 1 つの can、代理の 1 行。どれも処理者 P の候補ごとに
 * 業務処理と同じ 1 か所 {@see CaseOperationDecider} を呼んで求め、新しい判定を書かない。問い合わせは代理元の数に比例する
 * (引戻せるノードは処理者ごとに読む)が、案件のノードの数には比例しない。
 *
 * @phpstan-import-type Person from WorkflowPeople
 * @phpstan-import-type NodeAction from CaseNodeActions
 * @phpstan-import-type PullBackNode from CaseRows
 *
 * @phpstan-type DetailPullBackNode array{id: int, name: string, blockedReason: string|null, canAttach: bool,
 *     principalUserId: int|null}
 * @phpstan-type DetailCan array{pullBack: bool, reapply: bool, withdraw: bool}
 * @phpstan-type Choices array{nodeActions: list<NodeAction>, pullBackNodes: list<DetailPullBackNode>, can: DetailCan,
 *     casePrincipal: int|null, proxyNote: string|null, canAttachOnWithdraw: bool}
 */
class CaseProcessorChoices
{
    public function __construct(
        private CaseOperationDecider $decider,
        private CaseNodeActions $nodeActions,
        private CaseAttachEntries $attachEntries,
        private WorkflowProxies $proxies,
        private FlowPermissions $flowPermissions,
        private SubjectGroupMembership $membership,
        private WorkflowPeople $people,
        private ReapplyPrincipals $reapplyPrincipals,
    ) {}

    /**
     * @param  CaseSituation  $own  自分を処理者として読んだ状況
     * @param  array<int, WorkflowNodeKind>  $kinds  案件のノードの id → 種類(写した並び)
     * @return Choices
     */
    public function of(Requester $requester, AccessContext $context, CaseSituation $own, array $kinds): array
    {
        $date = $context->date;
        $candidates = $this->candidatesOf($requester, $context, $own);
        $people = $candidates === null ? [] : $this->people->users(
            [$candidates->userId, ...$candidates->principalIds()], $date, $context->localeCode,
        );
        $nodeActions = $this->nodeActions->of($requester, $date, $own, $candidates, $people);
        $ownCan = $this->decider->detailCanOf($requester, $date, $own);
        $withdrawAs = $this->withdrawPrincipalOf($requester, $context, $own, $candidates);
        $canWithdraw = $ownCan['withdraw'] || $withdrawAs !== null;
        $attach = $this->attachEntries->of([$own], [$own->case->id => $canWithdraw])[$own->case->id];
        $pullBackNodes = $this->pullBackNodesOf($requester, $context, $own, $candidates, $kinds, $attach['pullBack']);
        $hasOwnCaseOperation = $ownCan['withdraw'] || $ownCan['reapply'];
        $reapplyAs = $this->reapplyPrincipals->of($requester, $date, [$own])[$own->case->id] ?? null;
        $caseOperationAs = $withdrawAs ?? $reapplyAs;
        $canReapply = $ownCan['reapply'] || $reapplyAs !== null;

        return [
            'nodeActions' => $nodeActions,
            'pullBackNodes' => $pullBackNodes,
            'can' => ['pullBack' => $pullBackNodes !== [], 'reapply' => $canReapply, 'withdraw' => $canWithdraw],
            'casePrincipal' => $own->case->user_id === $candidates?->userId ? null : $own->case->user_id,
            'proxyNote' => self::proxyNoteOf(
                $nodeActions, $pullBackNodes, $hasOwnCaseOperation, $caseOperationAs, $people,
            ),
            'canAttachOnWithdraw' => $attach['withdraw'],
        ];
    }

    /**
     * 処理者 P の候補(申請・承認のノードの種類ごと)。判定を求める者が d に有効でなければ null。operate の判定は保留中の現在のノードが
     * あるときだけ求める(案件の管理者の役割が効くのは保留解除だけ = 案件の運用/案件の操作/操作.md の 8 節)。
     */
    private function candidatesOf(
        Requester $requester,
        AccessContext $context,
        CaseSituation $own,
    ): ?ProcessorCandidates {
        $date = $context->date;
        $userId = $this->membership->effectiveUserId($requester, $date);

        if ($userId === null) {
            return null;
        }

        $flowId = $own->case->workflow_flow_id;
        $byKind = [];

        foreach ([WorkflowNodeKind::Apply, WorkflowNodeKind::Approve] as $kind) {
            $byKind[$kind->value] = $this->proxies->processorCandidatesOf($userId, $flowId, $kind, $date);
        }

        $isHeld = fn (CurrentNode $node) => $node->state === WorkflowNodeState::Hold;
        $canOperate = array_filter($own->currentNodes, $isHeld) !== []
            && $this->flowPermissions->canOperate($requester, $date, $flowId);

        return new ProcessorCandidates($userId, $byKind, $canOperate);
    }

    /**
     * 代理元(申請者)として取止めできるときの申請者(自分が申請者・申請の代理先でない・表が許さないなら null)。
     * 処理者の確かめと表は取止めの業務処理と同じ(申請のノードの種類の代理 = 案件の操作/README.md の 4.5 節)。
     */
    private function withdrawPrincipalOf(
        Requester $requester,
        AccessContext $context,
        CaseSituation $own,
        ?ProcessorCandidates $candidates,
    ): ?int {
        $applicant = $own->case->user_id;
        $applyNode = $own->currentApplyNode();
        $isPrincipal = $candidates !== null && $applicant !== $candidates->userId
            && in_array($applicant, $candidates->forKind(WorkflowNodeKind::Apply), true);

        if (! $isPrincipal || $applyNode === null) {
            return null;
        }

        $situation = $candidates->situationFor($own, $applicant);
        $date = $context->date;
        $operation = WorkflowOperation::Withdraw;
        $permits = $this->decider->permitsIn($operation, $requester, $date, $situation, $applyNode->id);

        return $permits ? $applicant : null;
    }

    /**
     * 引戻せるノード(写した並び): 自分が h の処理者のノード(処理者 null)と、代理元が h の処理者で、そのノードの種類の代理が効くノード
     * (処理者 = 代理元)。h の処理者は 1 人なので、同じノードが 2 つの処理者で出ることは無い。
     *
     * @param  array<int, WorkflowNodeKind>  $kinds
     * @param  array<int, bool>  $ownCanAttach  自分が引戻せるノードの id → 添付の可否
     * @return list<DetailPullBackNode>
     */
    private function pullBackNodesOf(
        Requester $requester,
        AccessContext $context,
        CaseSituation $situation,
        ?ProcessorCandidates $candidates,
        array $kinds,
        array $ownCanAttach,
    ): array {
        $rows = [];

        foreach (CaseRows::pullBackNodesOf($situation, $ownCanAttach) as $node) {
            $rows[] = self::pullBackRow($node, $node['canAttach'], null);
        }

        if ($candidates !== null) {
            $rows = [...$rows, ...$this->principalsPullBacks($requester, $context, $situation, $candidates, $kinds)];
        }

        $order = array_flip(array_keys($kinds));
        usort($rows, fn (array $a, array $b) => $order[$a['id']] <=> $order[$b['id']]);

        return $rows;
    }

    /**
     * 代理元ごとに、代理元を処理者として読んだ状況の引戻せるノード。
     *
     * @param  array<int, WorkflowNodeKind>  $kinds
     * @return list<DetailPullBackNode>
     */
    private function principalsPullBacks(
        Requester $requester,
        AccessContext $context,
        CaseSituation $own,
        ProcessorCandidates $candidates,
        array $kinds,
    ): array {
        $case = $own->case;
        $rows = [];

        foreach ($candidates->principalIds() as $principalId) {
            $principal = $this->decider->situationsOf($requester, $context->date, [$case], $principalId)[$case->id];
            $rows = [...$rows, ...$this->principalPullBacks($requester, $context, $principal, $candidates, $kinds)];
        }

        return $rows;
    }

    /**
     * 代理元を処理者として読んだ状況の引戻せるノードのうち、そのノードの種類の代理が効き、表が許すもの。
     *
     * @param  array<int, WorkflowNodeKind>  $kinds
     * @return list<DetailPullBackNode>
     */
    private function principalPullBacks(
        Requester $requester,
        AccessContext $context,
        CaseSituation $situation,
        ProcessorCandidates $candidates,
        array $kinds,
    ): array {
        $principalId = $situation->processorUserId;
        $permits = fn (int $nodeId) => $this->decider
            ->permitsIn(WorkflowOperation::PullBack, $requester, $context->date, $situation, $nodeId);
        $isEffective = fn (array $node) => in_array($principalId, $candidates->forKind($kinds[$node['id']]), true)
            && $permits($node['id']);
        $nodes = array_values(array_filter(CaseRows::pullBackNodesOf($situation), $isEffective));

        if ($nodes === []) {
            return [];
        }

        $canAttach = $this->attachEntries->of([$situation], [])[$situation->case->id]['pullBack'];

        $rows = [];

        foreach ($nodes as $node) {
            $rows[] = self::pullBackRow($node, $canAttach[$node['id']] ?? false, $principalId);
        }

        return $rows;
    }

    /**
     * @param  PullBackNode  $node
     * @return DetailPullBackNode
     */
    private static function pullBackRow(array $node, bool $canAttach, ?int $principalUserId): array
    {
        return [
            'id' => $node['id'],
            'name' => $node['name'],
            'blockedReason' => $node['blockedReason'],
            'canAttach' => $canAttach,
            'principalUserId' => $principalUserId,
        ];
    }

    /**
     * 代理の 1 行(案件の画面.md の 4 節): 本人として処理できる操作があれば null。無ければ、代理元として処理できる操作の代理元の
     * 人数で文を選ぶ(並び = 選択肢と同じ昇順)。数える操作 = 処理の操作(案件の操作/README.md の 4.3 節。確認は代理が無いので数えない)。
     *
     * @param  list<NodeAction>  $nodeActions
     * @param  list<DetailPullBackNode>  $pullBackNodes
     * @param  bool  $hasOwnCaseOperation  本人として取止め・再申請できる
     * @param  int|null  $withdrawPrincipal  代理元として取止め・再申請できるときの申請者
     * @param  array<int, Person>  $people
     */
    private static function proxyNoteOf(
        array $nodeActions,
        array $pullBackNodes,
        bool $hasOwnCaseOperation,
        ?int $withdrawPrincipal,
        array $people,
    ): ?string {
        $principalsOf = fn (array $action) => array_merge([], ...array_values($action['principals']));
        $principals = array_merge([], ...array_map($principalsOf, $nodeActions));
        $pullBackIds = array_column($pullBackNodes, 'principalUserId');
        $hasOwn = $hasOwnCaseOperation || in_array(null, $pullBackIds, true)
            || array_filter($principals, fn (array $principal) => $principal['isSelf']) !== [];
        $ids = [...array_column($principals, 'id'), ...array_filter($pullBackIds), $withdrawPrincipal];
        $ids = array_values(array_unique(array_filter($ids, 'is_int')));
        sort($ids);

        if ($hasOwn || $ids === []) {
            return null;
        }

        $names = array_map(fn (int $id) => ($people[$id]['name'] ?? $people[$id]['code'] ?? '').'さん', $ids);

        if (count($names) === 1) {
            return "{$names[0]}の代理として処理します。";
        }

        return implode('、', $names).'の代理として処理できます。';
    }
}
