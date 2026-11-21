<?php

namespace App\Actions\Ai\Core;

use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;

/**
 * 道具の引数の形の誤りを、道具の約束の形(JSON Schema の配列)から 1 行ずつ組む中核の約束の部品(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の
 * 並べて動かすの組の分け方と、包みの引数の形の検証 = 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B11 が同じ照らし方を使う)。
 * JSON Schema の型を PHP の値の型で照らし、変換しない。列挙の形は、選べる値に無い値も誤りにする。
 */
final class ToolArguments
{
    /**
     * 道具の引数の項目を、引数の全体の 1 つのオブジェクトの JSON Schema にする(SDK に渡す形と同じ作り方)。
     *
     * @param  array<string, Type>  $properties
     * @return array<string, mixed>
     */
    public static function objectSchema(array $properties): array
    {
        return (new ObjectType($properties))->toArray();
    }

    /**
     * @param  array<array-key, mixed>  $schema  引数の全体の 1 つのオブジェクトの形
     * @param  array<array-key, mixed>  $arguments
     * @return list<string>
     */
    public static function problems(array $schema, array $arguments): array
    {
        $problems = self::objectProblems($schema, $arguments, '');
        $known = array_map(strval(...), array_keys(self::properties($schema)));

        foreach (array_keys($arguments) as $key) {
            if (! in_array((string) $key, $known, true)) {
                $problems[] = "{$key}: 知らない鍵";
            }
        }

        return $problems;
    }

    /**
     * 形の型の名前(null を含む)。
     *
     * @param  array<array-key, mixed>  $schema
     * @return list<string>
     */
    public static function types(array $schema): array
    {
        $types = $schema['type'] ?? [];

        return array_values(array_filter(is_array($types) ? $types : [$types], is_string(...)));
    }

    /**
     * オブジェクトの形の項目(schema の順)。
     *
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, array<array-key, mixed>>
     */
    public static function properties(array $schema): array
    {
        $properties = $schema['properties'] ?? [];

        return is_array($properties) ? array_filter($properties, is_array(...)) : [];
    }

    public static function join(string $path, string $key): string
    {
        return $path === '' ? $key : "{$path}.{$key}";
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

        foreach (self::properties($schema) as $key => $property) {
            $keyPath = self::join($path, (string) $key);

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
        $types = self::types($schema);
        $type = array_values(array_diff($types, ['null']))[0] ?? 'null';

        if ($value === null && in_array('null', $types, true)) {
            return [];
        }

        if (! self::fits($type, $value)) {
            return ["{$path}: 型が違う({$type})"];
        }

        if (! is_array($value)) {
            return self::enumProblems($schema, $value, $path);
        }

        if ($type === 'object') {
            return self::objectProblems($schema, $value, $path);
        }

        return is_array($schema['items'] ?? null) ? self::itemProblems($schema['items'], $value, $path) : [];
    }

    /**
     * 列挙の形の値が、選べる値に無いときの誤り(選べる値を並べる)。
     *
     * @param  array<array-key, mixed>  $schema
     * @return list<string>
     */
    private static function enumProblems(array $schema, mixed $value, string $path): array
    {
        $enum = is_array($schema['enum'] ?? null) ? $schema['enum'] : null;

        if ($enum === null || in_array($value, $enum, true)) {
            return [];
        }

        $allowed = implode('・', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $enum));

        return ["{$path}: 選べない値(選べる値: {$allowed})"];
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
            array_push($problems, ...self::valueProblems($items, $item, self::join($path, (string) $index)));
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
