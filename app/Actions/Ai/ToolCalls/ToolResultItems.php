<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Instructions\SharedInstructionTemplates;
use App\Enums\Ai\AiInstructionTemplate;
use App\Queries\Ai\Api\RecordMarkers;

/**
 * 包みの失敗の結果の項目(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4)。呼ばれた時の包みと書く時の部品が同じ形の結果を返す
 * ように、組み立てを 1 か所に置く。
 */
class ToolResultItems
{
    public function __construct(private readonly SharedInstructionTemplates $shared) {}

    /**
     * 失敗の印の付いた定型の結果。
     *
     * @param  array<string, string|list<string>>  $values
     */
    public static function failure(string $callId, AiInstructionTemplate $template, array $values): ConversationItem
    {
        return ConversationItem::toolResult($callId, $template, $values, null, true);
    }

    /** 道具の例外と包みの中の例外の結果(道具の名前だけを入れた決まった失敗の文 = ③ の定型。例外の文は入れない) */
    public function exception(string $callId, string $toolName): ConversationItem
    {
        $text = $this->shared->text(AiInstructionTemplate::ToolException, [
            'tool_name' => RecordMarkers::neutralize($toolName),
        ]);

        return self::failure($callId, AiInstructionTemplate::ToolError, ['error' => $text]);
    }
}
