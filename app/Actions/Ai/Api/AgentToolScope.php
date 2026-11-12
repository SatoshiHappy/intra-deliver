<?php

namespace App\Actions\Ai\Api;

/**
 * 道具ごとの渡せる範囲(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の 1 段目): 範囲の業務モジュールのコードと名前と、実行の始めに
 * 読んだ定義(10.4。モデルに渡した道具の定義と B1 の確かめに同じ定義を使う)。並びは道具の対象の並び。
 */
final readonly class AgentToolScope
{
    /**
     * @param  array<string, array{name: string, definition: array<string, mixed>}>  $modules  業務モジュールのコード → 名前と定義
     */
    public function __construct(public array $modules = []) {}

    /**
     * 範囲の業務モジュールのコード。
     *
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(strval(...), array_keys($this->modules));
    }

    public function has(string $moduleCode): bool
    {
        return isset($this->modules[$moduleCode]);
    }

    public function isEmpty(): bool
    {
        return $this->modules === [];
    }
}
