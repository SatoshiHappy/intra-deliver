<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 案件の閲覧の判定(読み取りの 1 か所。docs の 設計/ワークフロー/認可と一覧.md 2 節、述語 = 設計/ワークフロー/案件の運用/認可と一覧.md の 1 節)。
 * 一覧・件数・詳細・関連する案件・操作の業務処理は、どれもここの絞り込みを通る(一覧に出る案件と開ける案件が食い違わない)。
 * 「自分」は {@see SubjectGroupMembership::effectiveUserId()}。鍵を取らない(操作の業務処理は案件の行に鍵を取った後に {@see self::isVisible()} を呼ぶ)。
 */
class CaseVisibility
{
    public function __construct(
        private SubjectGroupMembership $membership,
        private CaseTargetTerms $targetTerms,
        private WorkflowProxies $proxies,
    ) {}

    /**
     * 閲覧できる案件に絞る(述語の和)。
     *
     * @param  Builder<WorkflowCase>  $query
     * @return Builder<WorkflowCase>
     */
    public function visible(Builder $query, Requester $requester, DateTimeInterface $date): Builder
    {
        $userId = $this->membership->effectiveUserId($requester, $date);

        if ($userId === null) {
            return $this->nothing($query);
        }

        $referencedFlowIds = $this->referencedFlowIds($requester, $date);

        return $query->where(fn (Builder $visible) => $visible
            ->where(fn (Builder $case) => $this->whereDraft($case, $userId, $date))
            ->orWhere(fn (Builder $case) => $this->wherePending($case, $userId, $date))
            ->orWhere(fn (Builder $case) => $this->whereProcessed($case, $userId, $date))
            ->orWhere(fn (Builder $case) => $this->whereReferenced($case, $referencedFlowIds)));
    }

    /**
     * 一時保存の述語(設計/ワークフロー/案件の運用/認可と一覧.md の 1 節)。
     *
     * @param  Builder<WorkflowCase>  $query
     * @return Builder<WorkflowCase>
     */
    public function draft(Builder $query, Requester $requester, DateTimeInterface $date): Builder
    {
        $userId = $this->membership->effectiveUserId($requester, $date);

        return $userId === null ? $this->nothing($query) : $query->where(fn (Builder $case) => $this->whereDraft($case, $userId, $date));
    }

    /**
     * 未処理の述語(ルートの深さ/認可と一覧.md 1 節。項は {@see CaseTargetTerms})。処理対象者の行はいつもノードの状態と組で見る
     * (処理済み・到達していないノードに残った行を拾わないため = 設計/ワークフロー/データモデル/表.md 2.7)。
     *
     * @param  Builder<WorkflowCase>  $query
     * @return Builder<WorkflowCase>
     */
    public function pending(Builder $query, Requester $requester, DateTimeInterface $date): Builder
    {
        $userId = $this->membership->effectiveUserId($requester, $date);

        return $userId === null ? $this->nothing($query) : $query->where(fn (Builder $case) => $this->wherePending($case, $userId, $date));
    }

    /**
     * 処理済の述語(設計/ワークフロー/案件の運用/認可と一覧.md の 1 節)。
     *
     * @param  Builder<WorkflowCase>  $query
     * @return Builder<WorkflowCase>
     */
    public function processed(Builder $query, Requester $requester, DateTimeInterface $date): Builder
    {
        $userId = $this->membership->effectiveUserId($requester, $date);

        return $userId === null ? $this->nothing($query) : $query->where(fn (Builder $case) => $this->whereProcessed($case, $userId, $date));
    }

    /**
     * 参照の述語: 一時保存でなく、フローの参照者の対象者に自分が合う(閲覧のたびに本人の判定。カレント組織 1 つで判定する)。
     *
     * @param  Builder<WorkflowCase>  $query
     * @return Builder<WorkflowCase>
     */
    public function referenced(Builder $query, Requester $requester, DateTimeInterface $date): Builder
    {
        if ($this->membership->effectiveUserId($requester, $date) === null) {
            return $this->nothing($query);
        }

        $referencedFlowIds = $this->referencedFlowIds($requester, $date);

        return $query->where(fn (Builder $case) => $this->whereReferenced($case, $referencedFlowIds));
    }

    /**
     * 案件が閲覧できるか(無い案件は偽)。{@see self::visible()} と同じ絞り込みを、案件の id で実行する。
     */
    public function isVisible(Requester $requester, DateTimeInterface $date, int $caseId): bool
    {
        return $this->visible(WorkflowCase::query()->whereKey($caseId), $requester, $date)->exists();
    }

    /**
     * @param  Builder<WorkflowCase>  $case
     */
    private function whereDraft(Builder $case, int $userId, DateTimeInterface $date): void
    {
        $owner = $case->qualifyColumn('user_id');
        $proxies = $this->proxies->rowsFor($userId, $date, $owner, $case->qualifyColumn('workflow_flow_id'))
            ->where('workflow_proxies.kind', WorkflowProxyKind::Apply);

        $case->where($case->qualifyColumn('state'), WorkflowCaseState::Draft)
            ->where(fn (Builder $draft) => $draft->where($owner, $userId)->orWhereExists($proxies));
    }

    /**
     * @param  Builder<WorkflowCase>  $case
     */
    private function wherePending(Builder $case, int $userId, DateTimeInterface $date): void
    {
        $case->where($case->qualifyColumn('state'), WorkflowCaseState::Active)
            ->whereHas('workflowCaseNodes', fn (Builder $node) => $node
                ->whereIn('state', WorkflowNodeState::current())
                ->whereHas('workflowCaseNodeTargets', fn (Builder $target) => $this->targetTerms->apply($target, $userId, $date)));
    }

    /**
     * @param  Builder<WorkflowCase>  $case
     */
    private function whereProcessed(Builder $case, int $userId, DateTimeInterface $date): void
    {
        $flow = $case->qualifyColumn('workflow_flow_id');
        $byProcessor = function (Builder $history) use ($userId, $date, $flow): void {
            $processor = $history->qualifyColumn('user_id');
            $history->where(fn (Builder $row) => $row->where($processor, $userId)
                ->orWhereExists($this->proxies->rowsFor($userId, $date, $processor, $flow)));
        };

        $case->where($case->qualifyColumn('state'), '<>', WorkflowCaseState::Draft)
            ->whereHas('workflowCaseHistories', $byProcessor);
    }

    /**
     * @param  Builder<WorkflowCase>  $case
     * @param  list<int>  $referencedFlowIds
     */
    private function whereReferenced(Builder $case, array $referencedFlowIds): void
    {
        $case->where($case->qualifyColumn('state'), '<>', WorkflowCaseState::Draft)->whereIn($case->qualifyColumn('workflow_flow_id'), $referencedFlowIds);
    }

    /**
     * 参照者の対象者に判定を求める者が合うフローの id。参照者の対象者を持つ全フローの対象者を 1 回の本人の判定に渡す(設計/ワークフロー/認可と一覧.md 2 節)。
     *
     * @return list<int>
     */
    private function referencedFlowIds(Requester $requester, DateTimeInterface $date): array
    {
        $flowIdsBySubjectGroup = WorkflowFlow::query()->whereNotNull('authz_subject_group_id')->pluck('authz_subject_group_id', 'id')->all();

        if ($flowIdsBySubjectGroup === []) {
            return [];
        }

        $matched = $this->membership->matchingIds($requester, array_values(array_unique(array_map('intval', $flowIdsBySubjectGroup))), $date);

        return array_keys(array_filter($flowIdsBySubjectGroup, fn (mixed $subjectGroupId) => in_array((int) $subjectGroupId, $matched, true)));
    }

    /**
     * 自分がいないときの絞り込み(どの案件にも合わない)。
     *
     * @param  Builder<WorkflowCase>  $query
     * @return Builder<WorkflowCase>
     */
    private function nothing(Builder $query): Builder
    {
        return $query->whereRaw('false');
    }
}
