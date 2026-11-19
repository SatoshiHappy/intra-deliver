<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\PriorToolCall;
use App\Actions\Ai\Api\RecordConfirmation;
use App\Enums\Ai\AiToolPartyForm;

/**
 * 承認待ちの呼び出しの、書く前の道具の検証のやり直し(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の「承認待ちの呼び出し」の
 * 検証のやり直し)。確認のカードを出してから答えるまでに定義と道具の版が変わりうるため。答えの実行の始めの定義で見て、名前を引き直さず
 * (書く値は利用者がカードで見た相手 = 10.5)、書く値の相手のうち確認の内容の今の値に無かった相手だけを基準日に有効か・木にあるかで見る。
 * 範囲の外になった業務モジュールの件は定義を使う確かめをしない(表 2 の P2・P3 で断る)。
 */
class ToolWriteRechecks
{
    public function __construct(private readonly ToolInputParties $inputs) {}

    /**
     * 誤りの文(表 2 の P9 の入力の誤り)。無ければ null。
     *
     * @param  array<string, mixed>  $written  問うの時に残した書く値の引数
     * @param  list<PriorToolCall>  $prior  同じステップで前に問うになった件(引数は書く値の引数)
     */
    public function problems(
        AgentRecordTool $tool,
        array $written,
        GrantedTool $granted,
        array $prior,
        GrantedTools $tools,
        AgentToolSubject $subject,
        RecordConfirmation $confirmation,
    ): ?string {
        $scope = $granted->scope;
        $module = $tool->moduleOf($written);

        if ($module === null || ! $scope->has($module)) {
            return null;
        }

        return ToolWriteChecks::atWrite($tool, $written, $scope, $prior, $tools)
            ?? $this->partyProblems($tool, $written, $scope, $module, $subject, $confirmation)
            ?? $tool->validate($written, $scope, $prior);
    }

    /**
     * 書く値の相手のうち今の値に無かった相手の誤り(名前の物は問うの時に id に直してあるので、名前の誤りは起きない)。
     *
     * @param  array<string, mixed>  $written
     */
    private function partyProblems(
        AgentRecordTool $tool,
        array $written,
        AgentToolScope $scope,
        string $module,
        AgentToolSubject $subject,
        RecordConfirmation $confirmation,
    ): ?string {
        $forms = array_filter(
            $tool->partyArguments(),
            fn (AiToolPartyForm $form): bool => $form !== AiToolPartyForm::Condition,
        );

        if ($forms === []) {
            return null;
        }

        $definition = $scope->modules[$module]['definition'];
        [, $slots, $walkErrors] = $this->inputs->resolve($written, $forms, $definition, $subject);
        $kept = self::currentKeys($confirmation->content);
        $added = array_values(array_filter(
            $slots,
            fn (array $slot): bool => ! in_array("{$slot['type']}:{$slot['id']}", $kept, true),
        ));
        $errors = $walkErrors === [] ? $this->inputs->invalid($added, $subject) : $walkErrors;

        return $errors === [] ? null : implode("\n", $errors);
    }

    /**
     * 確認の内容の今の値(項目と明細の今の値と参照制限の今の値の見せる人)に入った相手の種類と id。
     *
     * @param  array<string, mixed>  $content
     * @return list<string> `<種類>:<id>`
     */
    private static function currentKeys(array $content): array
    {
        $currentOf = fn (mixed $entry): mixed => is_array($entry) ? ($entry['current'] ?? null) : null;
        $listed = fn (string $key): array => is_array($content[$key] ?? null)
            ? array_map($currentOf, $content[$key])
            : [];
        $rowAccess = is_array($content['rowAccess'] ?? null) ? $content['rowAccess'] : [];
        $current = [
            ...$listed('fields'),
            ...$listed('details'),
            is_array($rowAccess['current'] ?? null) ? ($rowAccess['current']['viewers'] ?? null) : null,
        ];

        return array_values(array_unique(self::partyKeys($current)));
    }

    /**
     * 値の中の相手(種類がユーザーか組織で、整数の id を持つ物。入れ子の中まで)。
     *
     * @return list<string>
     */
    private static function partyKeys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $type = $value['type'] ?? null;
        $isParty = ($type === 'user' || $type === 'department') && is_int($value['id'] ?? null);
        $own = $isParty ? ["{$type}:{$value['id']}"] : [];

        return [...$own, ...array_merge([], ...array_map(self::partyKeys(...), array_values($value)))];
    }
}
