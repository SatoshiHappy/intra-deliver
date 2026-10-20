<?php

namespace App\Actions\Workflow;

/**
 * 定義の検査の違反 1 つ(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 3 節): 規則の番号と関わるノードのコード(テストと画面の文言が引く)。
 */
final readonly class RouteViolation
{
    /**
     * @param  string  $rule  規則の番号(G-2 など)
     * @param  list<string>  $nodeCodes  関わるノードのコード(G-9 = [Y, Z…]、G-10 = [Y, X] の順)
     */
    public function __construct(
        public string $rule,
        public array $nodeCodes,
    ) {}

    public function __toString(): string
    {
        return $this->rule.'('.implode(', ', $this->nodeCodes).')';
    }
}
