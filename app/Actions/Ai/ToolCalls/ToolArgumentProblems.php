<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentTool;
use App\Actions\Ai\Core\ToolArguments;

/**
 * 引数の形の誤り(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B11)。照らし方は中核の組の分け方と同じ 1 か所
 * ({@see ToolArguments})で、ここは道具の schema を JSON Schema にして渡す。
 */
class ToolArgumentProblems
{
    /**
     * @param  array<array-key, mixed>  $arguments
     * @return list<string>
     */
    public function of(AgentTool $tool, array $arguments): array
    {
        return ToolArguments::problems(ToolArgumentSchema::of($tool), $arguments);
    }
}
