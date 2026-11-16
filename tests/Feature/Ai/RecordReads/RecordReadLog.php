<?php

namespace Tests\Feature\Ai\RecordReads;

use Closure;

/**
 * 道具が呼んだ Records の読む口の記録(テストの中だけ。口の名前を呼ばれた順に残す)。口ごとに、元の口を呼ぶ前の関数を挟める
 * (判定の後の競合・例外を差し替えた口で起こす)。
 */
final class RecordReadLog
{
    /** @var list<string> 呼ばれた口(<クラスの短い名前>::<メソッド>) */
    public array $calls = [];

    /** @var array<string, Closure(): void> 口 → 元の口を呼ぶ前の関数 */
    public array $before = [];

    public function enter(string $operation): void
    {
        $this->calls[] = $operation;

        if (isset($this->before[$operation])) {
            ($this->before[$operation])();
        }
    }
}
