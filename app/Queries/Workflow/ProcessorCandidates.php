<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;

/**
 * 詳細の判定を求める者の処理者 P の候補(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節の「画面の選択肢」の集合 =
 * {@see WorkflowProxies::processorCandidatesOf()})。ノードの種類ごとに自分を先頭に、効く代理の代理元を昇順に持つ。値だけを持ち、DB を読まない。
 */
final readonly class ProcessorCandidates
{
    /**
     * @param  int  $userId  判定を求める者の d に有効なユーザー
     * @param  array<string, non-empty-list<int>>  $byKind  ノードの種類の値 → 候補(申請・承認のノードだけ。ほかの種類は自分だけ)
     * @param  bool  $canOperate  判定を求める者が案件のフローの operate を許されるか(案件の管理者の保留解除 =
     *                            案件の運用/案件の操作/操作.md の 8 節)
     */
    public function __construct(
        public int $userId,
        private array $byKind,
        public bool $canOperate,
    ) {}

    /**
     * その種類のノードを操作するときの候補(自分が先頭)。
     *
     * @return non-empty-list<int>
     */
    public function forKind(WorkflowNodeKind $kind): array
    {
        return $this->byKind[$kind->value] ?? [$this->userId];
    }

    /**
     * どれかの種類で候補になる代理元(昇順・重ねない。自分を含まない)。
     *
     * @return list<int>
     */
    public function principalIds(): array
    {
        $ids = array_diff(array_unique(array_merge([], ...array_values($this->byKind))), [$this->userId]);
        sort($ids);

        return $ids;
    }

    /**
     * 処理者を P とした状況(自分なら読んだ状況そのもの。代理元は {@see CaseSituation::withProcessor()} = 引戻せるノードを持たない)。
     */
    public function situationFor(CaseSituation $own, int $processor): CaseSituation
    {
        return $processor === $this->userId ? $own : $own->withProcessor($processor);
    }
}
