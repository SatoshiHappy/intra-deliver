<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentTool;
use App\Actions\Ai\Core\ToolArguments;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;

/**
 * 道具の引数の形を JSON Schema の配列にし、包みが照らせない形を探す(引数の形の誤りの出どころは道具の schema だけ = docs の
 * 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B11。照らせない形は起動の時に断る = 設計/AI/データと失敗と検査.md 8 節)。
 */
final class ToolArgumentSchema
{
    /**
     * 引数の全体を 1 つのオブジェクトの JSON Schema にした形(SDK に渡す形と同じ作り方)。
     *
     * @return array<string, mixed>
     */
    public static function of(AgentTool $tool): array
    {
        return ToolArguments::objectSchema($tool->schema(new JsonSchemaTypeFactory));
    }

    /**
     * 照らせない形の、鍵の道(点でつなぐ。リストの要素は「[]」)と種類。
     *
     * @param  array<array-key, mixed>  $schema
     * @return list<array{string, string}>
     */
    public static function unreadable(array $schema, string $path = ''): array
    {
        $found = [];

        if (isset($schema['anyOf'])) {
            return [[$path, 'anyOf']];
        }

        if (count(array_diff(ToolArguments::types($schema), ['null'])) > 1) {
            $found[] = [$path, '和の型'];
        }

        if (($schema['additionalProperties'] ?? null) === false) {
            $found[] = [$path, '余分な鍵の禁止'];
        }

        foreach (ToolArguments::properties($schema) as $key => $property) {
            array_push($found, ...self::unreadable($property, ToolArguments::join($path, (string) $key)));
        }

        if (is_array($schema['items'] ?? null)) {
            array_push($found, ...self::unreadable($schema['items'], $path.'[]'));
        }

        return $found;
    }
}
