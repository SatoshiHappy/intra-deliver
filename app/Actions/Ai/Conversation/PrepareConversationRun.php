<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ToolGrants;
use App\Actions\Ai\ToolCalls\ToolSubjects;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;

/**
 * 会話のジョブの 3 のうち、渡す道具を決めて実行の記録に残す所(docs の 設計/AI/エージェントの実行/README.md 9.1 のジョブの順、
 * 決め方 = 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の 1 段目)。定義の外し方と大きさの確かめ(設計/AI/設定とプロンプト/README.md
 * 11.3)は定義の口と一緒に置く。
 */
final readonly class PrepareConversationRun
{
    public function __construct(private ToolGrants $grants) {}

    public function execute(AiRun $run, AiAgentDefinition $definition): GrantedTools
    {
        $granted = $this->grants->grant($definition, ToolSubjects::forRun($run));

        $names = json_encode($granted->names(), JSON_THROW_ON_ERROR);
        AiRun::query()->whereKey($run->id)->update(['passed_tools' => $names]);

        return $granted;
    }
}
