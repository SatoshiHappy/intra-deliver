<?php

namespace App\Actions\Workflow;

/**
 * 形つきの版の辺 1 つの入力({@see RouteVersionWriter}。docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.4)。両端はノードのコード。
 * 分岐開始から出る辺だけが名前と条件(規則の条件の JSON。書く前に業務データの入れ物の口で検証・正規化する)を持つ。
 */
final readonly class RouteEdgeInput
{
    /**
     * @param  array<array-key, mixed>|null  $condition  {"all": [{field, op, value}, …]}
     */
    public function __construct(
        public string $fromCode,
        public string $toCode,
        public int $sortKey = 0,
        public ?string $label = null,
        public ?array $condition = null,
    ) {}
}
