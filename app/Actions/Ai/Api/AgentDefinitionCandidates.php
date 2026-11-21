<?php

namespace App\Actions\Ai\Api;

use App\Actions\Ai\ToolCalls\AgentTools;
use App\Enums\Ai\AiToolNature;
use App\Queries\Records\Api\RecordModuleDirectory;
use LogicException;

/**
 * 定義に選べる候補(使える道具 = 登録にある道具、ナレッジ = 業務モジュール。docs の 設計/AI/公開の口.md 3 節の定義の読み書きの行)。
 * 主体の判定をしない。道具の登録が業務処理の層にあるので、定義の読み取りと分けて置く。
 */
final readonly class AgentDefinitionCandidates
{
    public function __construct(private AgentTools $tools, private RecordModuleDirectory $directory) {}

    /**
     * 並び = {@see AgentTools::all()}(汎用の道具が先で名前の順 = docs の 設計/AI/道具と権限/README.md 10.1 の渡す道具の並び)。
     *
     * @return list<AgentToolCandidate>
     *
     * @throws LogicException 性質の起きない組の道具のとき(起動の時の確かめが止める作りの誤り)
     */
    public function tools(): array
    {
        return array_map(fn (AgentTool $tool): AgentToolCandidate => new AgentToolCandidate(
            $tool->name(),
            $tool->displayName(),
            AiToolNature::of($tool->isReadOnly(), $tool->requiredAction())
                ?? throw new LogicException("道具 {$tool->name()} の読むだけかと要る権限の組は起きない"),
            $tool->targetModules(),
        ), $this->tools->all());
    }

    /**
     * ナレッジに選べる業務モジュール(保存の時の確かめと同じ規則 = 表示の止めの無い親の無い業務モジュール。並びは業務モジュールの一覧と同じ)。
     *
     * @return list<array{code: string, name: string}>
     */
    public function knowledge(): array
    {
        return array_values(array_filter(
            $this->directory->topLevelModules(),
            fn (array $module): bool => $this->directory->visibilityStopReason($module['code']) === null,
        ));
    }
}
