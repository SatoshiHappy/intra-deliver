<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentToolSubject;
use App\Models\Ai\AiAgentDefinition;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordModuleDirectory;

/**
 * 実行の始めに渡す道具を決める 1 段目(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の 1 段目の表・10.4)。全体を使い回さない区間で
 * 1 つ囲むのは、同じジョブの前の判定の覚えに頼らず、今の権限で決めるため。
 */
class ToolGrants
{
    public function __construct(
        private AgentTools $tools,
        private RecordModuleDirectory $directory,
        private RecordFormQuery $forms,
        private ToolSubjects $subjects,
        private AuthorizationReuse $reuse,
    ) {}

    public function grant(AiAgentDefinition $definition, AgentToolSubject $subject): GrantedTools
    {
        return $this->reuse->without(fn (): GrantedTools => (new ToolGrantJudgements(
            $this->directory,
            $this->forms,
            fn (): bool => $this->subjects->isEffective($subject),
            $subject,
        ))->granted($this->tools, $definition));
    }
}
