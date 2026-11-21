<?php

namespace App\Actions\Ai\Core;

/**
 * 道具を動かす口の並べて動かすの返り値(docs の 設計/AI/会話の中核の口と受け手.md 13.3): 呼び出しの順の結果の列か、会話が無い。
 */
final readonly class ToolRuns
{
    /**
     * @param  list<ToolRun>  $runs
     */
    private function __construct(public array $runs, public bool $conversationGone) {}

    /**
     * @param  list<ToolRun>  $runs  呼び出しの順の結果(どれも道具の結果)
     */
    public static function results(array $runs): self
    {
        return new self($runs, false);
    }

    public static function noConversation(): self
    {
        return new self([], true);
    }
}
