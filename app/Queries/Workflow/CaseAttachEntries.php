<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCaseNode;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * 詳細と一覧の行の、引戻しと取止めの入口の canAttach(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 5 節)。
 * 可否は {@see CaseAttachability} の 1 か所で求め、ここは入口ごとに引数を組むだけ。引戻しは引戻す先のノード、取止めは申請の系統で、
 * 取止めは操作が許されるときだけ求める(入口が無ければ偽)。問い合わせはノードと業務モジュールの値をまとめて引き、案件の数に依らない。
 */
class CaseAttachEntries
{
    public function __construct(private CaseAttachability $attachability) {}

    /**
     * @param  list<CaseSituation>  $situations  案件のフローを読み込んだ状況
     * @param  array<int, bool>  $withdrawable  案件の id → 取止めが許されるか(can.withdraw)
     * @param  (Closure(): bool)|null  $moduleEnabled  詳細の 1 件の案件のフローの値(同じ表示で読んだ値を共有する。入口があるときに 1 回だけ呼ぶ)。null = ここで読む
     * @return array<int, array{pullBack: array<int, bool>, withdraw: bool}> 案件の id → 引戻せるノードの id ごとの値と取止めの値
     *
     * @throws LogicException 案件のフローを読み込んでいない・引戻せるノードの行が無い・値を渡したのに要る案件が 2 件以上のとき
     */
    public function of(array $situations, array $withdrawable, ?Closure $moduleEnabled = null): array
    {
        $pullBackIds = [];

        foreach ($situations as $situation) {
            $pullBackIds[$situation->case->id] = $this->pullBackIdsOf($situation);
        }

        $needed = array_values(array_filter(
            $situations,
            fn (CaseSituation $situation) => $pullBackIds[$situation->case->id] !== []
                || ($withdrawable[$situation->case->id] ?? false),
        ));
        $nodes = $this->nodesOf(array_merge([], ...array_values($pullBackIds)));
        $modules = $this->modulesOf($needed, $moduleEnabled);
        $result = array_fill_keys(array_keys($pullBackIds), ['pullBack' => [], 'withdraw' => false]);

        foreach ($needed as $situation) {
            $case = $situation->case;
            $flow = CaseRows::flowOf($case);
            $can = fn (WorkflowOperation $operation, ?WorkflowCaseNode $node = null) => $this->attachability->canAttach(
                $operation, $flow, $case, $node,
                moduleEnabled: $modules[$flow->id] ?? throw new LogicException("フロー {$flow->id} の値を読んでいない"),
            );
            $pullBack = [];

            foreach ($pullBackIds[$case->id] as $id) {
                $node = $nodes->get($id) ?? throw new LogicException("引戻せるノード {$id} の行が無い");
                $pullBack[$id] = $can(WorkflowOperation::PullBack, $node);
            }

            $withdraw = ($withdrawable[$case->id] ?? false) && $can(WorkflowOperation::Withdraw);
            $result[$case->id] = ['pullBack' => $pullBack, 'withdraw' => $withdraw];
        }

        return $result;
    }

    /**
     * 要る案件のフローごとの業務モジュールの値。値(遅延)を渡されたときは詳細の 1 件の案件のものとして使う。
     *
     * @param  list<CaseSituation>  $needed
     * @return array<int, bool> フローの id → 値
     *
     * @throws LogicException 値を渡したのに要る案件が 2 件以上のとき(呼び手の誤り)
     */
    private function modulesOf(array $needed, ?Closure $moduleEnabled): array
    {
        $flows = array_map(fn (CaseSituation $situation) => CaseRows::flowOf($situation->case), $needed);

        if ($moduleEnabled === null || $flows === []) {
            return $this->attachability->moduleEnabledByFlows($flows);
        }

        if (count($flows) > 1) {
            throw new LogicException('渡した業務モジュールの値は 1 件の案件のものなので、要る案件が 2 件以上では使えない');
        }

        return [$flows[0]->id => $moduleEnabled()];
    }

    /**
     * 画面に出す引戻しの行のノード(未完了の案件だけ = {@see CaseRows::pullBackNodesOf()})。
     *
     * @return list<int>
     */
    private function pullBackIdsOf(CaseSituation $situation): array
    {
        return array_column(CaseRows::pullBackNodesOf($situation), 'id');
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, WorkflowCaseNode> id → 行
     */
    private function nodesOf(array $ids): Collection
    {
        return $ids === [] ? new Collection : WorkflowCaseNode::query()->whereKey($ids)->get()->keyBy('id');
    }
}
