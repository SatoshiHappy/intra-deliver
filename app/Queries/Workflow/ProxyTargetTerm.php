<?php

namespace App\Queries\Workflow;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 「処理対象者に当たる」の代理の項(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.2 節)。項は判定する人から引き、
 * 行のユーザーを代理先として引かない(代理の代理を止めるため)。
 */
class ProxyTargetTerm implements CaseTargetTerm
{
    public function __construct(private WorkflowProxies $proxies) {}

    public function apply(Builder $targets, int $userId, DateTimeInterface $date): void
    {
        // 別の値はノードの種類の値と同じ語(展開の行も承認)なので、ノードの kind と列どうしで比べる
        $principal = $targets->qualifyColumn('user_id');
        $rows = $this->proxies->rowsFor($userId, $date, $principal, 'proxy_cases.workflow_flow_id')
            ->join('workflow_case_nodes as proxy_nodes', 'proxy_nodes.kind', '=', 'workflow_proxies.kind')
            ->join('workflow_cases as proxy_cases', 'proxy_cases.id', '=', 'proxy_nodes.workflow_case_id')
            ->whereColumn('proxy_nodes.id', $targets->qualifyColumn('workflow_case_node_id'));

        $targets->whereExists($rows);
    }
}
