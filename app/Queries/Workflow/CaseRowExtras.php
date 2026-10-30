<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseRowField;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use DateTimeInterface;

/**
 * 一覧の行の追加の項目(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.2 節、型 = 画面/README.md の 1.2 節)。呼び手が求めた項目
 * ({@see WorkflowCaseRowField})だけを作り、求めない項目は空の値にする(その項目の問い合わせをしない)。can.bulkApprove・bulkApproval・
 * can.confirm は判定の 1 か所 {@see CaseOperationDecider} が出す。問い合わせの回数は行の数に依らない。
 *
 * @phpstan-import-type Person from WorkflowPeople
 * @phpstan-import-type Steps from CaseConfirmations
 *
 * @phpstan-type Deadline array{reachedOn: string|null, elapsedDays: int|null, dueOn: string|null, overdue: bool}
 * @phpstan-type RowBulkApproval array{nodeId: int, nodeName: string, principal: Person|null}
 * @phpstan-type Extras array{
 *     deadline: Deadline|null, principals: list<Person>, confirmation: Steps|null, bulkApproval: RowBulkApproval|null,
 *     canBulkApprove: bool, canConfirm: bool,
 * }
 */
class CaseRowExtras
{
    /** 求めない項目の値 */
    public const NONE = [
        'deadline' => null, 'principals' => [], 'confirmation' => null, 'bulkApproval' => null,
        'canBulkApprove' => false, 'canConfirm' => false,
    ];

    public function __construct(
        private SubjectGroupMembership $membership,
        private CaseOperationDecider $decider,
        private CaseDeadlines $deadlines,
        private CaseConfirmations $confirmations,
        private WorkflowProxies $proxies,
        private WorkflowPeople $people,
    ) {}

    /**
     * @param  array<int, CaseSituation>  $situations  案件の id → 自分を処理者として読んだ状況
     * @param  list<WorkflowCaseRowField>  $fields  求める項目(判定を求める者が d に有効でなければ、どれも空の値)
     * @return array<int, Extras> 案件の id → 追加の項目
     */
    public function of(
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
        array $situations,
        array $fields,
    ): array {
        $userId = $this->membership->effectiveUserId($requester, $date);

        if ($userId === null || $fields === []) {
            return array_map(fn () => self::NONE, $situations);
        }

        $wants = fn (WorkflowCaseRowField $field) => in_array($field, $fields, true);
        $approvals = $wants(WorkflowCaseRowField::BulkApproval)
            ? $this->decider->bulkApprovalsOf($requester, $date, array_values($situations)) : [];
        $principals = $wants(WorkflowCaseRowField::Principals) ? $this->principalsOf($userId, $date, $situations) : [];
        $confirmations = $this->confirmationsOf($userId, array_keys($situations), $fields);
        $people = $this->peopleOf($principals, $approvals, $date, $localeCode);
        $extras = [];

        foreach ($situations as $caseId => $situation) {
            $approval = $approvals[$caseId] ?? null;
            $extras[$caseId] = [
                'deadline' => $this->deadlineOf($situation, $date, $fields),
                'principals' => array_map(fn (int $id) => $people[$id], $principals[$caseId] ?? []),
                'confirmation' => $confirmations[$caseId] ?? null,
                'bulkApproval' => $approval === null ? null : self::approvalOf($approval, $people),
                'canBulkApprove' => $approval !== null,
                'canConfirm' => $wants(WorkflowCaseRowField::OpenConfirmation)
                    && $this->decider->canConfirm($requester, $date, $situation),
            ];
        }

        return $extras;
    }

    /**
     * 経過日数・期限(案件の操作/README.md の 1.4 節の案件ごとの値)。対象 = 未処理の集まりか、現在のノードの全部(申請操作の一覧)。
     * 到達した日は集まりで最も早いもの。どちらも求めなければ null。
     *
     * @param  list<WorkflowCaseRowField>  $fields
     * @return Deadline|null
     */
    private function deadlineOf(CaseSituation $situation, DateTimeInterface $today, array $fields): ?array
    {
        $current = $situation->currentNodes;
        $targets = $situation->targetNodeIds;
        $wants = fn (WorkflowCaseRowField $field) => in_array($field, $fields, true);
        $nodes = match (true) {
            $wants(WorkflowCaseRowField::Deadline) => $this->deadlines->pendingNodesOf($current, $targets),
            $wants(WorkflowCaseRowField::CurrentDeadline) => $current,
            default => null,
        };

        return $nodes === null ? null : $this->deadlines->viewOf($nodes, $today);
    }

    /**
     * 確認するステップ(未確認の行)か確認したステップ(確認済の行)。
     *
     * @param  list<int>  $caseIds
     * @param  list<WorkflowCaseRowField>  $fields
     * @return array<int, Steps>
     */
    private function confirmationsOf(int $userId, array $caseIds, array $fields): array
    {
        if (in_array(WorkflowCaseRowField::OpenConfirmation, $fields, true)) {
            return $this->confirmations->unconfirmedStepsOf($caseIds, $userId);
        }

        if (in_array(WorkflowCaseRowField::DoneConfirmation, $fields, true)) {
            return $this->confirmations->confirmedStepsOf($caseIds, $userId);
        }

        return [];
    }

    /**
     * 代理元と一括承認の組の代理元の表示(1 回で引く)。
     *
     * @param  array<int, list<int>>  $principals
     * @param  array<int, BulkApproval|null>  $approvals
     * @return array<int, Person>
     */
    private function peopleOf(array $principals, array $approvals, DateTimeInterface $date, string $localeCode): array
    {
        $approvalPrincipals = array_filter(array_map(
            fn (?BulkApproval $approval) => $approval?->principalUserId,
            $approvals,
        ));
        $ids = array_values(array_unique([...array_merge([], ...array_values($principals)), ...$approvalPrincipals]));

        return $ids === [] ? [] : $this->people->users($ids, $date, $localeCode);
    }

    /**
     * 案件ごとの代理元: 未処理の集まりのうち、自分が本人として当たらないノードの処理対象者で、自分をそのノードの種類の代理先とする
     * d に有効で案件のフローに効く代理の代理元(昇順・重ねない)。代理の表は種類ごとに 1 回だけ引く。
     *
     * @param  array<int, CaseSituation>  $situations
     * @return array<int, list<int>> 案件の id → 代理元のユーザーの id
     */
    private function principalsOf(int $userId, DateTimeInterface $date, array $situations): array
    {
        $proxyOnly = array_map(fn (CaseSituation $situation) => array_values(array_filter(
            $this->deadlines->pendingNodesOf($situation->currentNodes, $situation->targetNodeIds),
            fn (CurrentNode $node) => ! in_array($userId, $node->targetUserIds, true),
        )), $situations);
        $flowIdOf = fn (CaseSituation $situation) => $situation->case->workflow_flow_id;
        $flowIds = array_values(array_unique(array_map($flowIdOf, $situations)));
        $kinds = array_unique(array_map(
            fn (CurrentNode $node) => WorkflowProxies::kindForNode($node->kind)?->value,
            array_merge([], ...array_values($proxyOnly)),
        ));
        $byKind = [];

        foreach (array_filter($kinds) as $kind) {
            $proxyKind = WorkflowProxyKind::from($kind);
            $byKind[$kind] = $this->proxies->principalIdsByFlow($userId, $flowIds, $proxyKind, $date);
        }

        return array_map(fn (CaseSituation $situation) => self::principalIdsOn(
            $proxyOnly[$situation->case->id],
            $situation->case->workflow_flow_id,
            $byKind,
        ), $situations);
    }

    /**
     * ノードの処理対象者のうち、そのノードの種類の代理の代理元(昇順・重ねない)。
     *
     * @param  list<CurrentNode>  $nodes
     * @param  array<string, array<int, list<int>>>  $byKind  代理の別 → フローの id → 代理元
     * @return list<int>
     */
    private static function principalIdsOn(array $nodes, int $flowId, array $byKind): array
    {
        $ids = [];

        foreach ($nodes as $node) {
            $kind = WorkflowProxies::kindForNode($node->kind)?->value;
            $effective = $byKind[$kind ?? ''][$flowId] ?? [];
            $ids = [...$ids, ...array_intersect($node->targetUserIds, $effective)];
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  array<int, Person>  $people
     * @return RowBulkApproval
     */
    private static function approvalOf(BulkApproval $approval, array $people): array
    {
        $principal = $approval->principalUserId === null ? null : $people[$approval->principalUserId];

        return ['nodeId' => $approval->nodeId, 'nodeName' => $approval->nodeName, 'principal' => $principal];
    }
}
