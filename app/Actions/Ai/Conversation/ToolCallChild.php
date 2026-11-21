<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\ToolCalls\AgentTools;
use App\Actions\Ai\ToolCalls\GrantedTool;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ToolCallStages;
use App\Actions\Ai\ToolCalls\ToolStep;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use LogicException;

/**
 * 並べて動かす組の子の処理の中身(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4): 実行の id から実行とステップの行を読み直し、
 * 渡された実行の始めの値で道具を組み直して、1 つずつ動かす組と同じ包みの手順 3〜6 を通す(依頼者と判定の主体は包みが実行から作り直す。親の
 * 判定と文脈を持ち込まない)。道具の行は子が書き、会話の保存への結果は書かずに返す。
 */
final readonly class ToolCallChild
{
    public function __construct(private ToolCallStages $stages, private AgentTools $tools) {}

    /**
     * @return array<string, mixed> 結果の項目の写しと、後の呼び出しの確かめに渡す前の呼び出しの値(問うなら asked)
     *
     * @throws LogicException 実行・ステップ・道具が無いとき(作りの誤り)
     */
    public function run(ToolCallChildInput $input): array
    {
        $run = AiRun::query()->findOrFail($input->runId);
        $step = AiRunStep::query()->whereKey($input->stepId)->where('ai_run_id', $run->id)->firstOrFail();
        $tool = $this->tools->named($input->toolName)
            ?? throw new LogicException("道具 {$input->toolName} が登録に無い");
        $granted = new GrantedTool($tool, $input->scope);
        $collected = new CollectedItems;
        $toolStep = new ToolStep($run, $step, new GrantedTools([$granted], []), $collected);

        [$result, $prior] = $this->stages->through($toolStep, $input->position, $input->call, $granted, $input->prior);

        if ($result === null) {
            throw new LogicException('子の処理の会話の保存が無しを返した');
        }

        if ($result['item'] === null) {
            return ['asked' => true];
        }

        return [
            'item' => $result['item']->toArray(),
            'prior' => ToolCallChildInput::priorArray($prior),
        ];
    }
}
