<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ToolGrants;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;

/**
 * 実行の始めの定義の大きさの確かめと、渡す道具の決め方と記録(docs の 設計/AI/エージェントの実行/README.md 9.1、
 * 確かめ = 設計/AI/設定とプロンプト/README.md 11.3、決め方 = 設計/AI/道具と権限/道具の判定と今の権限.md 10.3)。
 */
final readonly class PrepareConversationRun
{
    public function __construct(private ToolGrants $grants, private DefinitionToolSize $size) {}

    /**
     * @throws DefinitionTooLarge 外した後の道具の定義の大きさが上限を超えるとき
     */
    public function execute(AiRun $run, AiAgentDefinition $definition): GrantedTools
    {
        if ($this->size->exceeds($definition)) {
            throw new DefinitionTooLarge("定義 {$definition->id} の道具の定義の大きさが上限を超える(実行 {$run->id})");
        }

        $granted = $this->grants->grant($definition, ToolSubjects::forRun($run));

        $names = json_encode($granted->names(), JSON_THROW_ON_ERROR);
        AiRun::query()->whereKey($run->id)->update(['passed_tools' => $names]);

        return $granted;
    }
}
