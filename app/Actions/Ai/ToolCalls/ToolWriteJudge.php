<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Enums\Ai\AiToolNature;
use App\Enums\Ai\AiToolWriteDenial;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordModuleDirectory;
use LogicException;

/**
 * 判定の関数の「実行」の答え(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の「承認待ちの呼び出し」・設計/AI/道具と権限/まとめた実行と送るデータ.md
 * 10.5 の表 2 の P1〜P3)。権限を書く操作だけで見るのは、書く時のレコードの見え方は入れ物の判定する更新が見るため。呼び手が使い回さない区間の
 * 中で呼ぶ(設計/AI/道具と権限/読み書きのたびの今の権限.md 10.4)。
 */
class ToolWriteJudge
{
    public function __construct(
        private readonly RecordModuleDirectory $directory,
        private readonly RecordFormQuery $forms,
        private readonly ToolSubjects $subjects,
    ) {}

    /**
     * 断るなら表 2 の理由と null、許すなら null と書く主体(依頼者の今日の文脈)。
     *
     * @param  array<string, mixed>  $written  書く値の引数
     * @param  bool  $priorWritten  指した前の件がどれも書けたか(指さなければ真)
     * @return array{AiToolWriteDenial|null, AgentToolSubject|null}
     *
     * @throws LogicException 道具が書く道具でないとき・書く値の引数に業務モジュールが無いとき(作りの誤り)
     */
    public function decide(
        AgentRecordTool $tool,
        array $written,
        AiAgentDefinition $definition,
        AiRun $run,
        bool $priorWritten,
    ): array {
        $module = $tool->moduleOf($written) ?? throw new LogicException("道具 {$tool->name()} が書く業務モジュールを答えない");
        $action = match (AiToolNature::of($tool->isReadOnly(), $tool->requiredAction())) {
            AiToolNature::Create => RecordModuleAction::Create,
            AiToolNature::Update => RecordModuleAction::Update,
            default => throw new LogicException("道具 {$tool->name()} は書く道具でない"),
        };

        if (! $priorWritten) {
            return [AiToolWriteDenial::PriorNotWritten, null];
        }

        if (! $this->usable($tool, $module, $definition)) {
            return [AiToolWriteDenial::Unavailable, null];
        }

        $subject = $this->subjects->effective($run);

        return $subject !== null && $this->forms->allows($subject->requester(), $module, $action, $subject->date())
            ? [null, $subject]
            : [AiToolWriteDenial::NoPermission, null];
    }

    /** 道具と業務モジュールが使えるか(P2: 定義の使える道具・ナレッジ・表示の止め・業務モジュールの有無) */
    private function usable(AgentRecordTool $tool, string $module, AiAgentDefinition $definition): bool
    {
        $named = array_values(array_filter((array) $definition->tools, is_string(...)));
        $knowledge = array_values(array_filter((array) $definition->knowledge, is_string(...)));
        $targets = $tool->targetModules() ?? $knowledge;
        $modules = array_column($this->directory->topLevelModules(), 'code');

        return in_array($tool->name(), $named, true)
            && in_array($module, $targets, true)
            && in_array($module, $modules, true)
            && $this->directory->visibilityStopReason($module) === null;
    }
}
