<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\PriorToolCall;
use App\Enums\Ai\AiToolCallDecision;
use LogicException;

/**
 * 問う(docs の 設計/AI/実行の記録.md 2.4 の T5・設計/AI/エージェントの実行/道具の包みと止める.md 9.4 の 5)の道具の行を書く部品
 * ({@see ToolCallStages} の中だけで使う)。道具を動かさず、確認の内容(前の件を指す参照の k を足した物 = 設計/AI/道具と権限/まとめた実行と送るデータ.md
 * 10.5)と直すの版を書き、会話の保存には足さない(10.6 = 確認の内容はモデルに送らない)。後の呼び出しの前の呼び出しには、書く値を決めた引数を渡す
 * (設計/AI/道具と権限/README.md 10.1 の形の外の検証)。
 *
 * @phpstan-import-type Result from ToolCallStages
 */
class ToolCallAsks
{
    public function __construct(private ToolCallRows $rows) {}

    /**
     * @param  list<PriorToolCall>  $prior
     * @return array{Result, PriorToolCall}
     *
     * @throws LogicException 問うの答えに確認の内容か引数が無いとき・道具が業務モジュールを読み書きしないとき(作りの誤り)
     */
    public function asked(
        ToolStep $step,
        int $position,
        ModelToolCall $call,
        GrantedTool $granted,
        ToolJudgement $judgement,
        array $prior,
    ): array {
        $tool = $granted->tool;
        $arguments = $judgement->arguments;
        $confirmation = $judgement->confirmation;

        if (! $tool instanceof AgentRecordTool || $arguments === null || $confirmation === null) {
            throw new LogicException("道具 {$tool->name()} の問うに確認の内容か引数が無い");
        }

        $module = $tool->moduleOf($arguments) ?? '';
        $definition = $granted->scope->modules[$module]['definition'] ?? [];
        $ks = ToolCallLinks::ks($tool, $arguments, $definition, $prior, $step->tools);
        $content = [...$confirmation->content, 'references' => (object) $ks];
        $row = $this->rows->asked($step, $position, $call, $content, $arguments, $judgement->version);
        $priorCall = new PriorToolCall($call->id, $call->name, $arguments, AiToolCallDecision::Ask);

        return [['callId' => $call->id, 'toolCallId' => $row->id, 'item' => null], $priorCall];
    }
}
