<?php

namespace App\Actions\Ai\Core;

/**
 * 道具の呼び出しの組(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の並べて動かす)。$parallel = 並べて動かすか。並べる組は並べてよい道具の
 * 続く 2 つ以上の呼び出し、並べない組は呼び出し 1 つ。
 */
final readonly class ToolGroup
{
    /**
     * @param  non-empty-list<CoreToolCall>  $calls  呼び出しの順
     */
    public function __construct(public bool $parallel, public array $calls) {}
}
