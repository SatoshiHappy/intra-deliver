<?php

namespace App\Actions\Ai\Core;

/**
 * 道具を動かす口(受け手。docs の 設計/AI/会話の中核の口と受け手.md 13.3。中核が呼ぶ順も同じ項)。返った結果は会話の保存に書き終えている。
 * $preRun = 先に動かす呼び出し(承認待ち)か。
 */
interface ToolRunner
{
    /** 道具を引く(無いときの返り方 = 13.3 の探す) */
    public function find(CoreToolCall $call, bool $preRun): ToolLookup;

    /** 包みを通して動かす(13.3 の動かす。先に動かす呼び出しに問うは起きない) */
    public function run(CoreToolCall $call, bool $preRun): ToolRun;

    /**
     * 並べてよい道具の組を並べて動かす(13.3 の並べて動かす。どの呼び出しも探すで見つけた物)。
     *
     * @param  non-empty-list<CoreToolCall>  $calls  呼び出しの順
     * @param  positive-int  $concurrency  同時に動かす数の上限
     */
    public function runParallel(array $calls, int $concurrency): ToolRuns;
}
