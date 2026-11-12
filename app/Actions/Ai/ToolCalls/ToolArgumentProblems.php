<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentTool;

/**
 * 引数の形の誤り(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B11)を、道具の schema の JSON Schema から 1 行ずつ組む。
 * JSON Schema の型を PHP の値の型で照らし、変換しない(型の違う値が道具に届いて道具の例外にならないように)。
 */
class ToolArgumentProblems
{
    /**
     * @param  array<array-key, mixed>  $arguments
     * @return list<string>
     */
    public function of(AgentTool $tool, array $arguments): array
    {
        $schema = ToolArgumentSchema::of($tool);
        $problems = self::objectProblems($schema, $arguments, '');
        $known = array_map(strval(...), array_keys(ToolArgumentSchema::properties($schema)));

        foreach (array_keys($arguments) as $key) {
            if (! in_array((string) $key, $known, true)) {
                $problems[] = "{$key}: 知らない鍵";
            }
        }

        return $problems;
    }

    /**
     * オブジェクトの値の、項目ごとの欠けと型の違い(schema の順。入れ子は深さ優先)。
     *
     * @param  array<array-key, mixed>  $schema
     * @param  array<array-key, mixed>  $value
     * @return list<string>
     */
    private static function objectProblems(array $schema, array $value, string $path): array
    {
        $required = is_array($schema['required'] ?? null) ? array_map(strval(...), $schema['required']) : [];
        $problems = [];

        foreach (ToolArgumentSchema::properties($schema) as $key => $property) {
            $keyPath = ToolArgumentSchema::join($path, (string) $key);

            if (! array_key_exists($key, $value)) {
                $problems[] = in_array((string) $key, $required, true) ? "{$keyPath}: 欠けている" : null;

                continue;
            }

            array_push($problems, ...self::valueProblems($property, $value[$key], $keyPath));
        }

        return array_values(array_filter($problems, is_string(...)));
    }

    /**
     * 値 1 つの型の違いと、型が合うときの中身の誤り。
     *
     * @param  array<array-key, mixed>  $schema
     * @return list<string>
     */
    private static function valueProblems(array $schema, mixed $value, string $path): array
    {
        $types = ToolArgumentSchema::types($schema);
        $type = array_values(array_diff($types, ['null']))[0] ?? 'null';

        if ($value === null && in_array('null', $types, true)) {
            return [];
        }

        if (! self::fits($type, $value)) {
            return ["{$path}: 型が違う({$type})"];
        }

        if (! is_array($value)) {
            return [];
        }

        if ($type === 'object') {
            return self::objectProblems($schema, $value, $path);
        }

        return is_array($schema['items'] ?? null) ? self::itemProblems($schema['items'], $value, $path) : [];
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @param  array<array-key, mixed>  $list
     * @return list<string>
     */
    private static function itemProblems(array $items, array $list, string $path): array
    {
        $problems = [];

        foreach ($list as $index => $item) {
            $itemPath = ToolArgumentSchema::join($path, (string) $index);
            array_push($problems, ...self::valueProblems($items, $item, $itemPath));
        }

        return $problems;
    }

    /** 値が型に合うか(JSON の {} と [] は PHP で見分けられないので、空の配列はオブジェクトにも合う) */
    private static function fits(string $type, mixed $value): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            default => false,
        };
    }
}
