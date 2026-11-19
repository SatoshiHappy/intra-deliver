<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentCreateTool;
use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentUpdateTool;
use App\Actions\Ai\Api\PriorToolCall;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolPartyForm;

/**
 * 書く道具の件どうしのつながりの確かめ(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の件どうしのつながり。誤り = 10.3 の B1)。
 * 指せるのは、同じステップの前の呼び出しのうち判定が問うの作るの仮の名前(`ref`)だけ。指した作るの業務モジュールは参照の項目の参照先と同じで
 * なければならない(通すと、書く時に作った id が別の業務モジュールの同じ id のレコードを指しうる)。同じレコードを直す件は 1 枚の確認に 1 つ。
 * k(確認の内容に出す、問うの中の順)もここで数える。作るの仮の名前の鍵 `ref` と直すのレコードの鍵 `id` は、書く道具に共通の引数の鍵
 * (10.1 の作る・直すの引数)。参照の値を探すのは、相手を含む引数のうち入れ物の入力の形の項目の値と明細の行。
 *
 * @phpstan-type Asked array{tool: AgentRecordTool, arguments: array<string, mixed>, module: string|null, k: int}
 */
final class ToolCallLinks
{
    /** 作るの引数の仮の名前の鍵と、前の件を指す参照の値の鍵 */
    public const REF = 'ref';

    /** 直すの引数のレコードの id の鍵 */
    public const ID = 'id';

    /** 前の件を指す参照の値 {"ref": "<仮の名前>"} の仮の名前(参照の値でなければ null) */
    public static function refName(mixed $value): ?string
    {
        return is_array($value) && array_keys($value) === [self::REF] && is_string($value[self::REF])
            ? $value[self::REF]
            : null;
    }

    /**
     * つながりの誤りの文。
     *
     * @param  array<string, mixed>  $arguments  相手を id に直した引数
     * @param  array<array-key, mixed>  $definition  実行の始めの定義
     * @param  list<PriorToolCall>  $prior
     * @return list<string>
     */
    public static function problems(
        AgentRecordTool $tool,
        array $arguments,
        array $definition,
        array $prior,
        GrantedTools $tools,
    ): array {
        $asked = self::asked($prior, $tools);
        $creates = self::creates($asked);
        $ref = $arguments[self::REF] ?? null;
        $problems = [
            ...($tool instanceof AgentCreateTool && is_string($ref) && isset($creates[$ref])
                ? ["仮の名前 `{$ref}` は、同じ応答の前の作るが使っている。別の名前にする。"] : []),
            ...($tool instanceof AgentUpdateTool && self::updatesTheSameRecord($tool, $arguments, $asked)
                ? ['同じ応答の前の直すが、同じレコードを直す。1 つの直すにまとめる。'] : []),
        ];

        foreach (self::references($tool, $arguments, $definition) as [$label, $name, $target]) {
            $problems[] = self::referenceProblem($label, $name, $target, $creates);
        }

        return array_values(array_filter($problems, is_string(...)));
    }

    /**
     * 確認の内容に出す、前の件を指す参照の仮の名前 → k(引数に参照が無ければ空)。
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<array-key, mixed>  $definition
     * @param  list<PriorToolCall>  $prior
     * @return array<string, int>
     */
    public static function ks(
        AgentRecordTool $tool,
        array $arguments,
        array $definition,
        array $prior,
        GrantedTools $tools,
    ): array {
        $creates = self::creates(self::asked($prior, $tools));
        $ks = [];

        foreach (self::references($tool, $arguments, $definition) as [, $name]) {
            if (isset($creates[$name])) {
                $ks[$name] = $creates[$name]['k'];
            }
        }

        return $ks;
    }

    /**
     * 同じステップの前の、判定が問うの業務データを書く呼び出し(k = 問うの中の順)。
     *
     * @param  list<PriorToolCall>  $prior
     * @return list<Asked>
     */
    private static function asked(array $prior, GrantedTools $tools): array
    {
        $asked = [];

        foreach ($prior as $call) {
            $tool = $tools->find($call->toolName)?->tool;

            $arguments = $call->decision === AiToolCallDecision::Ask ? $call->arguments : null;

            if ($arguments !== null && $tool instanceof AgentRecordTool) {
                $module = $tool->moduleOf($arguments);
                $asked[] = ['tool' => $tool, 'arguments' => $arguments, 'module' => $module, 'k' => count($asked) + 1];
            }
        }

        return $asked;
    }

    /**
     * 問うの作るの仮の名前 → 件(先の件を残す)。
     *
     * @param  list<Asked>  $asked
     * @return array<string, Asked>
     */
    private static function creates(array $asked): array
    {
        $creates = [];

        foreach ($asked as $call) {
            $ref = $call['arguments'][self::REF] ?? null;

            if ($call['tool'] instanceof AgentCreateTool && is_string($ref)) {
                $creates[$ref] ??= $call;
            }
        }

        return $creates;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<Asked>  $asked
     */
    private static function updatesTheSameRecord(AgentUpdateTool $tool, array $arguments, array $asked): bool
    {
        foreach ($asked as $call) {
            $same = $call['tool'] instanceof AgentUpdateTool
                && $call['module'] === $tool->moduleOf($arguments)
                && ($call['arguments'][self::ID] ?? null) === ($arguments[self::ID] ?? null);

            if ($same) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, Asked>  $creates
     */
    private static function referenceProblem(string $label, string $name, ?string $target, array $creates): ?string
    {
        $create = $creates[$name] ?? null;

        return match (true) {
            $create === null => "{$label} の {\"ref\": \"{$name}\"} が指す作るが無い。指せるのは、同じ応答で前に呼んで確認に出た作るの仮の名前だけ。",
            $create['module'] !== $target => "{$label} の {\"ref\": \"{$name}\"} が指す作るの業務モジュール {$create['module']} は、"
                ."項目の参照先 {$target} と違う。",
            default => null,
        };
    }

    /**
     * 引数の中の前の件を指す参照(置き場の語・仮の名前・項目の参照先の業務モジュール)。
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<array-key, mixed>  $definition
     * @return list<array{string, string, string|null}>
     */
    private static function references(AgentRecordTool $tool, array $arguments, array $definition): array
    {
        $found = [];

        foreach ($tool->partyArguments() as $key => $form) {
            $value = is_array($arguments[$key] ?? null) ? $arguments[$key] : [];
            $found = [...$found, ...match ($form) {
                AiToolPartyForm::Input => self::valueReferences($value, $definition, ''),
                AiToolPartyForm::InputDetails => self::detailReferences($value, $definition),
                default => [],
            }];
        }

        return $found;
    }

    /**
     * @param  array<array-key, mixed>  $details
     * @param  array<array-key, mixed>  $definition
     * @return list<array{string, string, string|null}>
     */
    private static function detailReferences(array $details, array $definition): array
    {
        $children = ToolPartyFields::children($definition);
        $found = [];

        foreach ($details as $code => $rows) {
            foreach (is_array($rows) ? array_values($rows) : [] as $index => $row) {
                $values = is_array($row) && is_array($row['values'] ?? null) ? $row['values'] : [];
                $place = "明細 `{$code}` の ".($index + 1).' 行目の';
                $found = [...$found, ...self::valueReferences($values, $children[(string) $code] ?? [], $place)];
            }
        }

        return $found;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $definition
     * @return list<array{string, string, string|null}>
     */
    private static function valueReferences(array $values, array $definition, string $place): array
    {
        $fields = ToolFieldValues::fields($definition);
        $found = [];

        foreach ($values as $code => $value) {
            $field = $fields[(string) $code] ?? [];

            if (($field['type'] ?? null) === 'record') {
                $found = [...$found, ...self::fieldReferences("{$place}項目 `{$code}`", $field, $value)];
            }
        }

        return $found;
    }

    /**
     * 他モジュール参照の項目 1 つの、前の件を指す参照(複数の値は要素ごと)。
     *
     * @param  array<array-key, mixed>  $field
     * @return list<array{string, string, string|null}>
     */
    private static function fieldReferences(string $label, array $field, mixed $value): array
    {
        $target = is_array($field['referencedModule'] ?? null) ? ($field['referencedModule']['code'] ?? null) : null;
        $elements = is_array($value) && array_is_list($value) ? $value : [$value];
        $names = array_values(array_filter(array_map(self::refName(...), $elements), is_string(...)));

        return array_map(fn (string $name): array => [$label, $name, is_string($target) ? $target : null], $names);
    }
}
