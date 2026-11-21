<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentTool;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Core\ConversationMarkers;
use LogicException;

/**
 * 範囲に入れた業務モジュールの名前と定義を道具に添える(実行の始めの 1 段目と、定義の道具の定義の大きさの確かめが同じ形の道具を作るため。docs の
 * 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の 1 段目・設計/AI/設定とプロンプト/README.md 11.3)。
 */
final class ToolScopes
{
    /**
     * 範囲が空でない道具に、範囲の業務モジュールの名前と定義と、説明用の名前の写しを添える(範囲が null の道具は範囲を見ない)。
     *
     * @param  list<array{AgentTool, list<string>|null}>  $ranges
     * @param  array<string, string>  $names  業務モジュールのコード → 名前
     * @param  array<string, array<string, mixed>>  $definitions  読めた業務モジュールの定義(読めない業務モジュールは範囲に入れない)
     * @param  array<string, bool>  $defaults  参照制限の既定
     * @param  list<string>  $markerForms  会話の印の形
     * @return list<GrantedTool>
     *
     * @throws LogicException 定義を読めた範囲の業務モジュールの参照制限の既定が無いとき(作りの誤り)
     */
    public static function of(
        array $ranges,
        array $names,
        array $definitions,
        array $defaults,
        array $markerForms,
    ): array {
        $granted = [];

        foreach ($ranges as [$tool, $codes]) {
            [$modules, $shown, $rowAccessDefaults] = [[], [], []];

            foreach ($codes ?? [] as $code) {
                if (isset($definitions[$code], $names[$code])) {
                    $modules[$code] = ['name' => $names[$code], 'definition' => $definitions[$code]];
                    $rowAccessDefaults[$code] = $defaults[$code]
                        ?? throw new LogicException("範囲の業務モジュール {$code} の参照制限の既定が無い");
                    $shown[$code] = [
                        'name' => ConversationMarkers::broken($names[$code], $markerForms),
                        'definition' => ShownDefinitions::of($definitions[$code], $markerForms),
                    ];
                }
            }

            if ($codes === null || $modules !== []) {
                $granted[] = new GrantedTool($tool, new AgentToolScope($modules, $shown, $rowAccessDefaults));
            }
        }

        return $granted;
    }
}
