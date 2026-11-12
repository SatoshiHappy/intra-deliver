<?php

namespace App\Actions\Ai\ToolCalls;

/**
 * 実行の始めに渡す道具の並び(docs の 設計/AI/道具と権限/README.md 10.1 の渡す道具の並び)と、定義の使える道具のうち登録に無くて外した名前
 * (設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の A5。① が管理者に知らせる)。
 */
final readonly class GrantedTools
{
    /**
     * @param  list<GrantedTool>  $tools
     * @param  list<string>  $removedNames
     */
    public function __construct(public array $tools, public array $removedNames) {}

    /** 渡す道具の名前と別名で 1 回で探す(10.1 の名前)。無ければ null */
    public function find(string $name): ?GrantedTool
    {
        foreach ($this->tools as $granted) {
            if ($granted->tool->name() === $name || in_array($name, $granted->tool->aliases(), true)) {
                return $granted;
            }
        }

        return null;
    }

    /**
     * 渡す道具の名前(並びのまま)。
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(fn (GrantedTool $granted): string => $granted->tool->name(), $this->tools);
    }
}
