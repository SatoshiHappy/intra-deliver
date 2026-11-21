<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentTool;
use App\Enums\Ai\AiToolNature;
use App\Models\Ai\AiAgentDefinition;
use Closure;
use LogicException;

/**
 * 定義の使える道具ごとの範囲の業務モジュール(docs の 設計/AI/設定とプロンプト/README.md 11.3 の外す物と
 * 設計/AI/道具と権限/読み書きのたびの今の権限.md 10.4 の 1 段目が同じ外し方を使うよう、1 か所に置く)。業務モジュールを範囲に入れるかは呼び手の述語が決める。
 */
final class ToolRanges
{
    /**
     * @param  Closure(string, AiToolNature): bool  $passes  業務モジュールのコードと道具の性質 → 範囲に入れるか
     * @return list<array{AgentTool, list<string>|null}> {@see AgentTools::all()} の並びの、定義の使える道具と範囲(ファイルを作る道具は null =
     *                                                   範囲を見ない)
     *
     * @throws LogicException 性質の起きない組の道具のとき(起動の時の確かめが止める作りの誤り)
     */
    public static function of(AgentTools $tools, AiAgentDefinition $definition, Closure $passes): array
    {
        $named = array_values(array_filter((array) $definition->tools, is_string(...)));
        $knowledge = array_values(array_filter((array) $definition->knowledge, is_string(...)));
        $ranges = [];

        foreach ($tools->all() as $tool) {
            if (in_array($tool->name(), $named, true)) {
                $ranges[] = [$tool, self::range($tool, $knowledge, $passes)];
            }
        }

        return $ranges;
    }

    /**
     * @param  list<string>  $knowledge
     * @param  Closure(string, AiToolNature): bool  $passes
     * @return list<string>|null
     */
    private static function range(AgentTool $tool, array $knowledge, Closure $passes): ?array
    {
        $nature = AiToolNature::of($tool->isReadOnly(), $tool->requiredAction())
            ?? throw new LogicException("道具 {$tool->name()} の読むだけかと要る権限の組は起きない");

        if ($nature === AiToolNature::CreateFile) {
            return null;
        }

        $targets = array_values(array_unique($tool->targetModules() ?? $knowledge));

        return array_values(array_filter($targets, static fn (string $code): bool => $passes($code, $nature)));
    }
}
