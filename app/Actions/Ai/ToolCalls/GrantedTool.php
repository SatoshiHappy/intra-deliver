<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentTool;
use App\Actions\Ai\Api\AgentToolScope;

/**
 * 実行の始めに渡す道具 1 つと、その渡せる範囲(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の 1 段目の A3・A4)。
 */
final readonly class GrantedTool
{
    public function __construct(public AgentTool $tool, public AgentToolScope $scope) {}
}
