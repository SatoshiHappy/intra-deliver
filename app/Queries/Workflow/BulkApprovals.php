<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowOperation;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Workflow\WorkflowCaseNode;
use DateTimeInterface;

/**
 * 一括承認で選べる組の判定(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節)。組ごとの可否は単独の承認と同じ
 * 状態 × 役割の表({@see CaseOperationTable})を処理者ごとに引き、一括のための別の判定を書かない。呼び手は {@see CaseOperationDecider} だけ
 * (行の can.bulkApprove と処理の時の組を同じ 1 か所で決めるため)。問い合わせは案件の数に依らない。鍵を取らない。
 *
 * @phpstan-type Pair array{CurrentNode, int}
 */
class BulkApprovals
{
    public function __construct(
        private CaseOperationTable $table,
        private ProcessorRoles $roles,
        private WorkflowProxies $proxies,
        private ApplicantApprovalGuard $applicantGuard,
    ) {}

    /**
     * 案件ごとの、ちょうど 1 つの組(無いか 2 つ以上なら null)。
     *
     * @param  int|null  $userId  判定を求める者の d に有効なユーザー(無ければ null)
     * @param  list<CaseSituation>  $situations  自分を処理者として読んだ状況
     * @return array<int, BulkApproval|null> 案件の id → 組
     */
    public function of(?int $userId, DateTimeInterface $date, array $situations): array
    {
        $result = [];

        foreach ($situations as $situation) {
            $result[$situation->case->id] = null;
        }

        if ($userId === null || $situations === []) {
            return $result;
        }

        $flowIdOf = fn (CaseSituation $situation) => $situation->case->workflow_flow_id;
        $flowIds = array_values(array_unique(array_map($flowIdOf, $situations)));
        $principals = $this->proxies->principalIdsByFlow($userId, $flowIds, WorkflowProxyKind::Approve, $date);
        $pairs = [];

        foreach ($situations as $situation) {
            $principalIds = $principals[$flowIdOf($situation)] ?? [];
            $pairs[$situation->case->id] = $this->permittedPairs($userId, $situation, $principalIds);
        }

        foreach ($this->withoutBlocked($userId, $situations, $pairs) as $caseId => $casePairs) {
            $result[$caseId] = count($casePairs) === 1 ? self::approvalOf($userId, $casePairs[0]) : null;
        }

        return $result;
    }

    /**
     * 表が承認を許す組(現在のノード × 処理者の候補 = 自分と承認の代理の代理元)。同じノードに自分と代理元の両方が当たれば自分として 1 つに数える。
     *
     * @param  list<int>  $principalIds  自分を承認の代理先とする、案件のフローに効く代理の代理元
     * @return list<Pair>
     */
    private function permittedPairs(int $userId, CaseSituation $situation, array $principalIds): array
    {
        $pairs = [];

        $candidates = array_values(array_unique([$userId, ...$principalIds]));

        foreach ($situation->currentNodes as $node) {
            $permits = fn (int $processor) => $this->permits($userId, $situation, $node, $processor);
            $processors = array_values(array_filter($candidates, $permits));

            foreach (in_array($userId, $processors, true) ? [$userId] : $processors as $processor) {
                $pairs[] = [$node, $processor];
            }
        }

        return $pairs;
    }

    private function permits(int $userId, CaseSituation $situation, CurrentNode $node, int $processor): bool
    {
        $roles = $this->roles->of($userId, $situation->withProcessor($processor), $node, false, false, false);
        $state = $situation->case->state;

        return $this->table->permits(WorkflowOperation::Approve, $state, $node->kind, $node->state, $roles);
    }

    /**
     * 指定するノード(指定の入力が要る)と、申請者承認防止に当たる組を除く(処理者 = 組の処理者、実行者 = 自分)。
     *
     * @param  list<CaseSituation>  $situations
     * @param  array<int, list<Pair>>  $pairs  案件の id → 組
     * @return array<int, list<Pair>>
     */
    private function withoutBlocked(int $userId, array $situations, array $pairs): array
    {
        $allPairs = array_merge([], ...array_values($pairs));
        $nodeIds = array_values(array_unique(array_map(fn (array $pair) => $pair[0]->id, $allPairs)));

        if ($nodeIds === []) {
            return $pairs;
        }

        $designators = WorkflowCaseNode::query()->whereIn('designator_workflow_case_node_id', $nodeIds)
            ->distinct()->pluck('designator_workflow_case_node_id')->all();
        $preventing = WorkflowCaseNode::query()->whereIn('id', $nodeIds)->where('prevents_applicant_approval', true)
            ->pluck('id')->all();
        $designators = array_map('intval', $designators);
        $preventing = array_values(array_map('intval', $preventing));
        $applicants = $this->applicantsOf($situations, $pairs, $preventing);
        $allowed = [];

        foreach ($situations as $situation) {
            $case = $situation->case;
            $isPrevented = fn (int $processor) => ApplicantApprovalGuard::termOf(
                new CaseActor($processor, $userId),
                $case->user_id,
                $applicants[$case->id] ?? [$case->user_id],
            ) !== null;
            $isBlocked = fn (array $pair) => in_array($pair[0]->id, $designators, true)
                || (in_array($pair[0]->id, $preventing, true) && $isPrevented($pair[1]));
            $isAllowed = fn (array $pair) => ! $isBlocked($pair);
            $allowed[$case->id] = array_values(array_filter($pairs[$case->id], $isAllowed));
        }

        return $allowed;
    }

    /**
     * 申請者承認防止がオンのノードの組を持つ案件だけの、申請者として扱う人(代理の表を引くので要る案件だけを 1 回で引く)。
     *
     * @param  list<CaseSituation>  $situations
     * @param  array<int, list<Pair>>  $pairs
     * @param  list<int>  $preventing  防止がオンのノードの id
     * @return array<int, non-empty-list<int>> 案件の id → ユーザーの id
     */
    private function applicantsOf(array $situations, array $pairs, array $preventing): array
    {
        $needed = array_filter($situations, fn (CaseSituation $situation) => array_filter(
            $pairs[$situation->case->id],
            fn (array $pair) => in_array($pair[0]->id, $preventing, true),
        ) !== []);

        $cases = array_values(array_map(fn (CaseSituation $situation) => $situation->case, $needed));

        return $cases === [] ? [] : $this->applicantGuard->applicantUserIdsByCase($cases);
    }

    /**
     * @param  Pair  $pair
     */
    private static function approvalOf(int $userId, array $pair): BulkApproval
    {
        [$node, $processor] = $pair;

        return new BulkApproval($node->id, $node->name, $processor === $userId ? null : $processor);
    }
}
