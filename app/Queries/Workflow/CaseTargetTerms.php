<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowCaseNodeTarget;
use DateTimeInterface;
use Illuminate\Container\Attributes\Tag;
use Illuminate\Database\Eloquent\Builder;

/**
 * 「処理対象者に当たる」の 1 か所(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 3 節 = 3b の差し込み口): タグ {@see CaseTargetTerm::TAG} で登録した
 * 項の OR。閲覧の判定の未処理の述語({@see CaseVisibility})・操作の役割「ノードの処理対象者」({@see CaseOperationDecider})・処理対象者の候補の入口・can は
 * どれもここを通る(項を足すとそろって変わる)。状態(現在のノードか)は呼び手が組で見る。項が 0 なら誰も当たらない。鍵を取らない。
 */
class CaseTargetTerms
{
    /**
     * @param  iterable<CaseTargetTerm>  $terms
     */
    public function __construct(#[Tag(CaseTargetTerm::TAG)] private iterable $terms) {}

    /**
     * 処理対象者の行の問い合わせに、項の OR の 1 組を足す。
     *
     * @param  Builder<WorkflowCaseNodeTarget>  $targets
     * @param  int  $userId  判定を求める者の、d に有効なユーザー
     */
    public function apply(Builder $targets, int $userId, DateTimeInterface $date): void
    {
        $terms = [...$this->terms];

        if ($terms === []) {
            $targets->whereRaw('false');

            return;
        }

        $targets->where(function (Builder $any) use ($terms, $userId, $date): void {
            foreach ($terms as $term) {
                $any->orWhere(fn (Builder $target) => $term->apply($target, $userId, $date));
            }
        });
    }

    /**
     * 渡したノードのうち、自分が処理対象者に当たるもの(ノードの状態は見ない)。
     *
     * @param  list<int>  $nodeIds
     * @return list<int> ノードの id(昇順・重ねない)
     */
    public function targetNodeIds(array $nodeIds, int $userId, DateTimeInterface $date): array
    {
        if ($nodeIds === []) {
            return [];
        }

        $query = WorkflowCaseNodeTarget::query()->whereIn('workflow_case_node_id', $nodeIds);
        $this->apply($query, $userId, $date);

        return array_values(array_map('intval', $query->distinct()->orderBy('workflow_case_node_id')->pluck('workflow_case_node_id')->all()));
    }
}
