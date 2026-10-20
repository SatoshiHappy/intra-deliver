<?php

namespace App\Queries\Workflow;

/**
 * ノードと辺の向きつきグラフ(版のルート・案件の写しのどちらにも使う。docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2 節の子孫・祖先・区間、
 * 定義の管理.md 3 節の定義の検査)。ノードは id、辺は渡した順(呼び手が sort_key の順に渡す)。展開の行は含めない(呼び手が親と一緒に扱う)。DB を読まない。
 */
final readonly class RouteGraph
{
    /**
     * @param  array<int, list<int>>  $outgoing  ノードの id → 出る辺の先(渡した順)
     * @param  array<int, list<int>>  $incoming  ノードの id → 入る辺の元(渡した順)
     */
    private function __construct(
        private array $outgoing,
        private array $incoming,
    ) {}

    /**
     * @param  list<int>  $nodeIds
     * @param  list<array{int, int}>  $edges  [出るノード, 入るノード](sort_key の順)
     */
    public static function of(array $nodeIds, array $edges): self
    {
        $outgoing = array_fill_keys($nodeIds, []);
        $incoming = array_fill_keys($nodeIds, []);

        foreach ($edges as [$from, $to]) {
            $outgoing[$from][] = $to;
            $incoming[$to][] = $from;
        }

        return new self($outgoing, $incoming);
    }

    /**
     * @return list<int> ノードの id(渡した順)
     */
    public function nodeIds(): array
    {
        return array_keys($this->outgoing);
    }

    /**
     * @return list<int> 出る辺の先(sort_key の順)
     */
    public function outgoing(int $nodeId): array
    {
        return $this->outgoing[$nodeId] ?? [];
    }

    /**
     * @return list<int> 入る辺の元
     */
    public function incoming(int $nodeId): array
    {
        return $this->incoming[$nodeId] ?? [];
    }

    /**
     * 辺で届くノード(自分を含まない。$blocked のノードには入らず、その先へも進まない)。
     *
     * @param  list<int>  $blocked
     * @return list<int> 届くノードの id(見つけた順)
     */
    public function reachableFrom(int $nodeId, array $blocked = []): array
    {
        return $this->walk($nodeId, $this->outgoing, $blocked);
    }

    /**
     * 辺を逆にたどって届くノード(祖先。自分を含まない)。
     *
     * @return list<int>
     */
    public function ancestorsOf(int $nodeId): array
    {
        return $this->walk($nodeId, $this->incoming, []);
    }

    /**
     * 区間(開始 S から対の終了 E の手前までに届くノード。S と E を含まない = 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.4)。
     *
     * @return list<int>
     */
    public function section(int $openingId, int $closingId): array
    {
        return $this->reachableFrom($openingId, [$closingId]);
    }

    /**
     * @param  array<int, list<int>>  $adjacency
     * @param  list<int>  $blocked
     * @return list<int>
     */
    private function walk(int $start, array $adjacency, array $blocked): array
    {
        $seen = [$start => true];
        $visit = [$start];

        for ($index = 0; $index < count($visit); $index++) {
            foreach ($adjacency[$visit[$index]] ?? [] as $next) {
                if (! isset($seen[$next]) && ! in_array($next, $blocked, true)) {
                    $seen[$next] = true;
                    $visit[] = $next;
                }
            }
        }

        return array_slice($visit, 1);
    }
}
