<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentCreateTool;
use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentUpdateTool;
use App\Actions\Ai\Api\PriorToolCall;
use App\Actions\Ai\Api\RecordCurrentValues;
use App\Enums\Ai\AiToolPartyForm;

/**
 * 書く道具の ③ に共通の確かめ(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の道具の検証 = B1 と、直すの今の値を使う検証 = B19)。
 * 呼ばれた時は、定義による項目と値の形と選択肢・件どうしのつながり・作るの参照制限の書く値(W 表。元 = 業務モジュールの既定)を見る。直すの 1 段目の
 * 後は、参照制限の書く値(元 = 今の値)を見る。定義と既定は実行の始めの範囲の物で、渡せる範囲の外の業務モジュールには掛けない(B12・B9 にする)。
 * 決めた参照制限の書く値は、渡さなかったキーを含めて引数に入れる(確認の内容に出し、書く時に両方渡す = 10.2)。
 *
 * @phpstan-import-type Viewer from ToolRowAccessWrites
 * @phpstan-import-type CurrentParty from RecordCurrentValues
 */
final class ToolWriteChecks
{
    /**
     * 呼ばれた時の確かめ。誤りは B1 の文、通れば直した引数。
     *
     * @param  array<string, mixed>  $arguments  相手を id に直した引数
     * @param  list<PriorToolCall>  $prior
     * @return array{array<string, mixed>, string|null}
     */
    public static function whenCalled(
        AgentRecordTool $tool,
        array $arguments,
        AgentToolScope $scope,
        array $prior,
        GrantedTools $tools,
    ): array {
        $module = $tool->moduleOf($arguments);
        $writes = $tool instanceof AgentCreateTool || $tool instanceof AgentUpdateTool;

        if (! $writes || $module === null || ! $scope->has($module)) {
            return [$arguments, null];
        }

        $definition = $scope->modules[$module]['definition'];
        $problems = [
            ...self::definitionProblems($tool, $arguments, $definition),
            ...ToolCallLinks::problems($tool, $arguments, $definition, $prior, $tools),
        ];

        if ($problems === [] && $tool instanceof AgentCreateTool && self::rowAccessKey($tool) !== null) {
            [$arguments, $problems] = self::rowAccess($tool, $arguments, $scope->rowAccessDefault($module), []);
        }

        return [$arguments, $problems === [] ? null : implode("\n", $problems)];
    }

    /**
     * 書く前の確かめのやり直し(10.3 の「承認待ちの呼び出し」の検証のやり直し): 定義による項目と選択肢・前の件を指す印・W8 を、書く値の引数
     * (参照制限の 2 つのキーは問うの時に決めた値)で見る。誤りは書く時の入力の誤り(表 2 の P9)の文。範囲の外の業務モジュールは見ない
     * (表 2 の P2・P3 で断る)。
     *
     * @param  array<string, mixed>  $written  問うの時に残した書く値の引数
     * @param  list<PriorToolCall>  $prior  同じステップで前に問うになった件(引数は書く値の引数)
     */
    public static function atWrite(
        AgentRecordTool $tool,
        array $written,
        AgentToolScope $scope,
        array $prior,
        GrantedTools $tools,
    ): ?string {
        $module = $tool->moduleOf($written);

        if ($module === null || ! $scope->has($module)) {
            return null;
        }

        $definition = $scope->modules[$module]['definition'];
        $problems = [
            ...self::definitionProblems($tool, $written, $definition),
            ...ToolCallLinks::problems($tool, $written, $definition, $prior, $tools),
        ];

        if ($problems === []) {
            [, $problems] = self::rowAccess($tool, $written, false, []);
        }

        return $problems === [] ? null : implode("\n", $problems);
    }

    /**
     * 直すの 1 段目の後の参照制限の確かめ(元 = 今の値)。誤りは B19 の文、通れば書く値を入れた引数。
     *
     * @param  array<string, mixed>  $arguments
     * @return array{array<string, mixed>, string|null}
     */
    public static function afterCurrent(AgentUpdateTool $tool, array $arguments, RecordCurrentValues $current): array
    {
        $viewers = array_map(
            fn (array $viewer): array => ['type' => $viewer['type'], 'id' => $viewer['id']],
            $current->rowAccess['viewers'],
        );
        [$arguments, $problems] = self::rowAccess($tool, $arguments, $current->rowAccess['restricted'], $viewers);

        return [$arguments, $problems === [] ? null : implode("\n", $problems)];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<array-key, mixed>  $definition
     * @return list<string>
     */
    private static function definitionProblems(AgentRecordTool $tool, array $arguments, array $definition): array
    {
        $problems = [];

        foreach ($tool->partyArguments() as $key => $form) {
            $value = is_array($arguments[$key] ?? null) ? $arguments[$key] : [];
            $problems = [...$problems, ...match ($form) {
                AiToolPartyForm::Input => ToolFieldValues::problems($value, $definition, ''),
                AiToolPartyForm::InputDetails => ToolFieldValues::detailProblems($value, $definition),
                default => [],
            }];
        }

        return $problems;
    }

    /** 参照制限の引数の鍵(持たない道具は null) */
    private static function rowAccessKey(AgentRecordTool $tool): ?string
    {
        $key = array_search(AiToolPartyForm::InputRowAccess, $tool->partyArguments(), true);

        return is_string($key) ? $key : null;
    }

    /**
     * 参照制限の書く値を W 表で決めて引数に入れる(参照制限の引数を持たない道具はそのまま)。
     *
     * @param  array<string, mixed>  $arguments
     * @param  list<Viewer>  $originViewers
     * @return array{array<string, mixed>, list<string>}
     */
    private static function rowAccess(
        AgentRecordTool $tool,
        array $arguments,
        bool $origin,
        array $originViewers,
    ): array {
        $key = self::rowAccessKey($tool);

        if ($key === null) {
            return [$arguments, []];
        }

        $input = is_array($arguments[$key] ?? null) ? $arguments[$key] : null;
        $decided = ToolRowAccessWrites::decide($input, $origin, $originViewers);

        if ($decided === null) {
            return [$arguments, [ToolRowAccessWrites::VIEWERS_WITHOUT_RESTRICTION]];
        }

        $arguments[$key] = $decided;

        return [$arguments, []];
    }
}
