<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Workflow\WorkflowProxy;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 代理の引き方の 1 か所(docs の 設計/ワークフロー/案件の運用/データモデル/表.md の 3.12 節、効く代理 = 設計/ワークフロー/案件の運用/案件の操作/README.md
 * の 4.1 節)。有効・フロー・別の条件をここだけに書き、代理を引く所の結果を食い違わせない。
 * 鍵を取らない(設計/ワークフロー/案件の運用/データモデル/README.md の 4.2 節)。
 */
class WorkflowProxies
{
    /** 代理の状態(並びの順 = 有効 → 開始前 → 終了。docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節) */
    public const STATUSES = ['active', 'upcoming', 'ended'];

    /** {@see self::orderedByStatus()} が足す、状態の {@see self::STATUSES} の添え字の列の別名 */
    public const STATUS_RANK = 'status_rank';

    /**
     * ノードに効く代理の別(ほかの種類に効く代理は無ければ null。設計/ワークフロー/案件の運用/案件の操作/README.md の
     * 4.1 節)。
     */
    public static function kindForNode(WorkflowNodeKind $kind): ?WorkflowProxyKind
    {
        return match ($kind) {
            WorkflowNodeKind::Apply => WorkflowProxyKind::Apply,
            WorkflowNodeKind::Approve => WorkflowProxyKind::Approve,
            default => null,
        };
    }

    /**
     * 自分を代理先とする、d に有効でそのフローに効く代理の代理元。
     *
     * @param  WorkflowProxyKind|null  $kind  代理の別(null = 別を問わない)
     * @return list<int> 代理元のユーザーの id(昇順・重ねない)
     */
    public function principalIdsOf(
        int $proxyUserId,
        int $flowId,
        ?WorkflowProxyKind $kind,
        DateTimeInterface $date,
    ): array {
        return $this->userIds($this->principalRows($proxyUserId, $flowId, $kind, $date), 'principal_user_id');
    }

    /**
     * 自分を代理先とする、d に有効でその別に効く代理の代理元を、渡したフローごとに(問い合わせはフローの数に依らず 1 回)。
     *
     * @param  list<int>  $flowIds
     * @return array<int, list<int>> フローの id → 代理元のユーザーの id(昇順・重ねない。代理元の無いフローは入れない)
     */
    public function principalIdsByFlow(
        int $proxyUserId,
        array $flowIds,
        WorkflowProxyKind $kind,
        DateTimeInterface $date,
    ): array {
        if ($flowIds === []) {
            return [];
        }

        $ofProxy = WorkflowProxy::query()->where('proxy_user_id', $proxyUserId)->where('kind', $kind);
        $rows = $this->effective($ofProxy, $date)
            ->where(fn (Builder $flow) => $flow->whereNull('workflow_flow_id')->orWhereIn('workflow_flow_id', $flowIds))
            ->orderBy('principal_user_id')->toBase()->get(['principal_user_id', 'workflow_flow_id']);
        $byFlow = [];

        foreach ($flowIds as $flowId) {
            $effective = $rows->filter(
                fn (object $row) => $row->workflow_flow_id === null || (int) $row->workflow_flow_id === $flowId,
            );
            $principalIds = array_values(array_map('intval', $effective->pluck('principal_user_id')->unique()->all()));

            if ($principalIds !== []) {
                $byFlow[$flowId] = $principalIds;
            }
        }

        return $byFlow;
    }

    /**
     * 処理者 P の候補(設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節の画面の選択肢と同じ集合。指定の候補の入口の 403 も
     * これを呼ぶ = 操作.md の 8 節): 自分を先頭に、ほかは d に有効でノードの種類と案件のフローに効く代理の代理元(昇順・重ねない)。
     *
     * @return non-empty-list<int> ユーザーの id
     */
    public function processorCandidatesOf(
        int $userId,
        int $flowId,
        WorkflowNodeKind $nodeKind,
        DateTimeInterface $date,
    ): array {
        $kind = self::kindForNode($nodeKind);
        $principals = $kind === null ? [] : $this->principalIdsOf($userId, $flowId, $kind, $date);

        return [$userId, ...array_values(array_diff($principals, [$userId]))];
    }

    /**
     * その人が、自分を代理先とする d に有効でそのフローとその別に効く代理の代理元か。
     */
    public function isPrincipalOf(
        int $proxyUserId,
        int $principalUserId,
        WorkflowProxyKind $kind,
        int $flowId,
        DateTimeInterface $date,
    ): bool {
        $rows = $this->principalRows($proxyUserId, $flowId, $kind, $date);

        return $rows->where('principal_user_id', $principalUserId)->exists();
    }

    /**
     * 代理元をその人とする、d に有効でそのフローに効く代理(別を問わない)の代理先。
     *
     * @return list<int> 代理先のユーザーの id(昇順・重ねない)
     */
    public function proxyIdsOf(int $principalUserId, int $flowId, DateTimeInterface $date): array
    {
        $proxies = $this->effective(WorkflowProxy::query()->where('principal_user_id', $principalUserId), $date);

        return $this->userIds($this->forFlow($proxies, $flowId), 'proxy_user_id');
    }

    /**
     * 代理元を渡した人のどれかとする、d に有効でそのフローとその別に効く代理の代理先(通知の宛先の代理先 =
     * 設計/ワークフロー/案件の運用/通知.md の 3 節)。問い合わせは 1 回。
     *
     * @param  list<int>  $principalUserIds
     * @return list<int> 代理先のユーザーの id(昇順・重ねない)
     */
    public function proxyIdsOfAny(
        array $principalUserIds,
        int $flowId,
        WorkflowProxyKind $kind,
        DateTimeInterface $date,
    ): array {
        if ($principalUserIds === []) {
            return [];
        }

        $proxies = $this->effective(WorkflowProxy::query()->whereIn('principal_user_id', $principalUserIds), $date);

        return $this->userIds($this->forFlow($proxies, $flowId)->where('kind', $kind), 'proxy_user_id');
    }

    /**
     * 外の問い合わせの行に掛ける EXISTS の中身: 自分を代理先とし d に有効で、代理元が外の列 $principalColumn の人、
     * フローが外の列 $flowColumn に効く代理の行。別は呼び手が足す(代理の表は (proxy_user_id, kind) の索引で引く
     * = 設計/ワークフロー/案件の運用/認可と一覧.md の 1 節)。
     *
     * @return Builder<WorkflowProxy>
     */
    public function rowsFor(
        int $proxyUserId,
        DateTimeInterface $date,
        string $principalColumn,
        string $flowColumn,
    ): Builder {
        $ofProxy = WorkflowProxy::query()->where('workflow_proxies.proxy_user_id', $proxyUserId);

        return $this->ofColumns($this->effective($ofProxy, $date), $principalColumn, $flowColumn);
    }

    /**
     * 案件ごとの、申請者を代理元とする d に有効で案件のフローに効く代理(別を問わない)の代理先。問い合わせは案件の数に依らず 1 回。
     *
     * @param  list<int>  $caseIds
     * @return array<int, list<int>> 案件の id → 代理先のユーザーの id(昇順・重ねない。代理先の無い案件は入れない)
     */
    public function applicantProxyIdsOf(array $caseIds, DateTimeInterface $date): array
    {
        if ($caseIds === []) {
            return [];
        }

        $proxies = $this->effective(WorkflowProxy::query()->crossJoin('workflow_cases'), $date);
        $rows = $this->ofColumns($proxies, 'workflow_cases.user_id', 'workflow_cases.workflow_flow_id')
            ->whereIn('workflow_cases.id', $caseIds)->distinct()
            ->orderBy('workflow_cases.id')->orderBy('workflow_proxies.proxy_user_id')
            ->toBase()->get(['workflow_cases.id as case_id', 'workflow_proxies.proxy_user_id']);
        $byCase = [];

        foreach ($rows as $row) {
            $byCase[(int) $row->case_id][] = (int) $row->proxy_user_id;
        }

        return $byCase;
    }

    /**
     * @return Builder<WorkflowProxy>
     */
    private function principalRows(
        int $proxyUserId,
        int $flowId,
        ?WorkflowProxyKind $kind,
        DateTimeInterface $date,
    ): Builder {
        $proxies = $this->effective(WorkflowProxy::query()->where('proxy_user_id', $proxyUserId), $date);
        $proxies = $this->forFlow($proxies, $flowId);

        return $kind === null ? $proxies : $proxies->where('kind', $kind);
    }

    /**
     * 代理の行に、d で決めた状態の添え字の列 {@see self::STATUS_RANK} を足し、状態 → 開始日の降順 → id の降順に並べる(境界は
     * {@see self::effective()} と同じ半開区間 [start_date, end_date))。
     *
     * @param  Builder<WorkflowProxy>  $proxies
     * @return Builder<WorkflowProxy>
     */
    public function orderedByStatus(Builder $proxies, DateTimeInterface $date): Builder
    {
        $day = $date->format('Y-m-d');
        // 添え字は定数から作った整数を文に埋める(then をバインドにすると case が文字列の型になり、並びが変わるため)
        $ranks = array_flip(self::STATUSES);
        $rank = "case when workflow_proxies.start_date > ? then {$ranks['upcoming']}"
            ." when workflow_proxies.end_date <= ? then {$ranks['ended']} else {$ranks['active']} end";

        return $proxies->select('workflow_proxies.*')->selectRaw("{$rank} as ".self::STATUS_RANK, [$day, $day])
            ->orderBy(self::STATUS_RANK)->orderByDesc('workflow_proxies.start_date')
            ->orderByDesc('workflow_proxies.id');
    }

    /**
     * d に有効な代理の行だけ(start_date <= d < end_date。end_date が NULL は上限なし)。
     *
     * @param  Builder<WorkflowProxy>  $proxies
     * @return Builder<WorkflowProxy>
     */
    public function effective(Builder $proxies, DateTimeInterface $date): Builder
    {
        $day = $date->format('Y-m-d');

        return $proxies->where('workflow_proxies.start_date', '<=', $day)
            ->where(fn (Builder $end) => $end->whereNull('workflow_proxies.end_date')
                ->orWhere('workflow_proxies.end_date', '>', $day));
    }

    /**
     * 代理元とフローを外の列と比べる(全フローの代理 = workflow_flow_id が NULL はどのフローにも効く)。
     *
     * @param  Builder<WorkflowProxy>  $proxies
     * @return Builder<WorkflowProxy>
     */
    private function ofColumns(Builder $proxies, string $principalColumn, string $flowColumn): Builder
    {
        return $proxies->whereColumn('workflow_proxies.principal_user_id', $principalColumn)
            ->where(fn (Builder $flow) => $flow->whereNull('workflow_proxies.workflow_flow_id')
                ->orWhereColumn('workflow_proxies.workflow_flow_id', $flowColumn));
    }

    /**
     * @param  Builder<WorkflowProxy>  $proxies
     * @return Builder<WorkflowProxy>
     */
    private function forFlow(Builder $proxies, int $flowId): Builder
    {
        return $proxies->where(fn (Builder $flow) => $flow->whereNull('workflow_flow_id')
            ->orWhere('workflow_flow_id', $flowId));
    }

    /**
     * @param  Builder<WorkflowProxy>  $proxies
     * @return list<int>
     */
    private function userIds(Builder $proxies, string $column): array
    {
        return array_values(array_map('intval', $proxies->distinct()->orderBy($column)->pluck($column)->all()));
    }
}
