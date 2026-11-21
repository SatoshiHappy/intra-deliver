<?php

namespace App\Actions\Ai\Core;

use Laravel\Ai\Contracts\Tool;

/**
 * 渡す道具の名前ごとの並べてよいかと同時に動かす数の上限(ループの受け取る物 = docs の 設計/AI/会話の中核の口と受け手.md 13.3。繋ぎが道具の約束から
 * 写す)。
 */
final readonly class ParallelTools
{
    /**
     * @param  array<string, bool>  $safe  送る名前 → 並べてよいか(無い名前は並べない)
     * @param  positive-int  $concurrency  同時に動かす数の上限
     */
    public function __construct(public array $safe, public int $concurrency) {}

    /**
     * @param  list<CoreToolCall>  $calls  呼び出しの順
     * @param  array<string, Tool>  $tools  渡す道具(送る名前 → 道具)
     * @return list<ToolGroup>
     */
    public function groups(array $calls, array $tools): array
    {
        return ToolGroups::of($calls, $tools, $this->safe);
    }
}
