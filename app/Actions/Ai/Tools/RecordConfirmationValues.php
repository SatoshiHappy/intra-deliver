<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Ai\Api\RecordCurrentValues;

/**
 * 確認の内容の欄ごとの今の値と書く値を組む部品({@see RecordConfirmations} の中だけで使う。docs の
 * 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5)。項目は渡した項目だけを出し(入力に無い項目は今のまま)、明細は渡した表ごとに今の行と
 * 書く行を出す(明細の入力は表の行の全部)。前の件を指す参照の値 {"ref": "…"} は書く値にそのまま置く。
 *
 * @phpstan-import-type CurrentParty from RecordCurrentValues
 *
 * @phpstan-type ShownParty array{type: string, id: int, code: string|null, name: string|null, affiliation: string|null}
 */
final class RecordConfirmationValues
{
    /** 相手を指す型 */
    private const PARTY_TYPES = ['user', 'department', 'user_or_department'];

    /**
     * 前の件を指す参照の値を空にした項目の値(画面の初期値の口に渡す物)。
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function withoutReferences(array $values): array
    {
        return array_map(function (mixed $value): mixed {
            if (self::isReference($value)) {
                return null;
            }

            return is_array($value) && array_is_list($value)
                ? array_values(array_filter($value, fn (mixed $element): bool => ! self::isReference($element)))
                : $value;
        }, $values);
    }

    /**
     * @param  array<array-key, mixed>  $details
     * @return array<array-key, mixed>
     */
    public static function detailsWithoutReferences(array $details): array
    {
        return array_map(fn (mixed $rows): array => array_map(
            fn (mixed $row): array => ['values' => self::withoutReferences(self::rowValues($row))],
            is_array($rows) ? array_values($rows) : [],
        ), $details);
    }

    /**
     * 項目の名前(親の項目と、明細のモジュールの項目)。
     *
     * @param  array<array-key, mixed>  $definition
     * @return array{fields: array<string, string>, children: array<string, array<string, string>>}
     */
    public static function names(array $definition): array
    {
        $names = ['fields' => [], 'children' => []];

        foreach (RecordToolValues::fields($definition) as $field) {
            if (is_string($field['code'] ?? null) && is_string($field['name'] ?? null)) {
                $names['fields'][$field['code']] = $field['name'];
                $child = is_array($field['childModule'] ?? null) ? $field['childModule'] : [];
                $names['children'][$field['code']] = self::names($child)['fields'];
            }
        }

        return $names;
    }

    /**
     * 渡した項目の今の値(直すだけ)と書く値。
     *
     * @param  array<array-key, mixed>  $values  書く値(入力の形)
     * @param  array<string, mixed>  $shown  書く値の画面の初期値の形
     * @param  array{fields: array<string, string>, children: array<string, array<string, string>>}  $names
     * @return array<string, array<string, mixed>>
     */
    public static function fields(array $values, array $shown, array $names, ?RecordCurrentValues $current): array
    {
        $fields = [];

        foreach ($values as $code => $value) {
            $code = (string) $code;
            $fields[$code] = [
                'name' => $names['fields'][$code] ?? $code,
                ...($current === null ? [] : ['current' => $current->values[$code] ?? null]),
                'next' => self::next($value, $shown[$code] ?? null),
            ];
        }

        return $fields;
    }

    /**
     * 渡した明細の表の今の行(直すだけ)と書く行。
     *
     * @param  array<array-key, mixed>  $details  書く行(入力の形)
     * @param  array<string, list<array{values: array<string, mixed>}>>  $shown  書く行の画面の初期値の形
     * @param  array{fields: array<string, string>, children: array<string, array<string, string>>}  $names
     * @return array<string, array<string, mixed>>
     */
    public static function details(array $details, array $shown, array $names, ?RecordCurrentValues $current): array
    {
        $tables = [];

        foreach ($details as $code => $rows) {
            $code = (string) $code;
            $next = [];

            foreach (is_array($rows) ? array_values($rows) : [] as $index => $row) {
                $next[] = self::rowOf($row, $shown[$code][$index]['values'] ?? []);
            }

            $tables[$code] = [
                'name' => $names['fields'][$code] ?? $code,
                ...($current === null ? [] : ['current' => $current->details[$code] ?? []]),
                'next' => $next,
            ];
        }

        return $tables;
    }

    /**
     * 相手の種類と id の列。
     *
     * @param  list<array<array-key, mixed>>  $parties
     * @return list<array{type: string, id: int}>
     */
    public static function keysOf(array $parties): array
    {
        $keys = [];

        foreach ($parties as $party) {
            if (is_string($party['type'] ?? null) && is_int($party['id'] ?? null)) {
                $keys[] = ['type' => $party['type'], 'id' => $party['id']];
            }
        }

        return $keys;
    }

    /**
     * 今の値の相手(項目の値・明細の行・見せる人。種類:id → 相手)。
     *
     * @param  array<array-key, mixed>  $definition
     * @return array<string, ShownParty>
     */
    public static function currentParties(RecordCurrentValues $current, array $definition): array
    {
        $found = [];

        foreach ($current->rowAccess['viewers'] as $viewer) {
            $found = self::withParty($found, $viewer['type'], $viewer);
        }

        $found = self::valueParties($found, $current->values, RecordToolValues::types($definition));

        foreach (RecordToolValues::fields($definition) as $field) {
            $child = is_array($field['childModule'] ?? null) ? $field['childModule'] : null;
            $code = is_string($field['code'] ?? null) ? $field['code'] : null;
            $rows = $child === null || $code === null ? [] : ($current->details[$code] ?? []);

            foreach ($rows as $row) {
                $found = self::valueParties($found, $row['values'], RecordToolValues::types($child));
            }
        }

        return $found;
    }

    /**
     * @param  array<string, ShownParty>  $found
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $types
     * @return array<string, ShownParty>
     */
    private static function valueParties(array $found, array $values, array $types): array
    {
        foreach (array_intersect($types, self::PARTY_TYPES) as $code => $type) {
            $value = $values[$code] ?? null;

            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $party) {
                $found = is_array($party) ? self::withParty($found, self::kindOf($type, $party), $party) : $found;
            }
        }

        return $found;
    }

    /**
     * 相手の種類(型「ユーザーと組織」は値の種類)。
     *
     * @param  array<array-key, mixed>  $party
     */
    private static function kindOf(string $type, array $party): string
    {
        $kind = $type === 'user_or_department' ? ($party['type'] ?? null) : $type;

        return is_string($kind) ? $kind : '';
    }

    /**
     * @param  array<string, ShownParty>  $found
     * @param  array<array-key, mixed>  $party  参照の値(id・コード・名前・所属)
     * @return array<string, ShownParty>
     */
    private static function withParty(array $found, string $type, array $party): array
    {
        if (! is_int($party['id'] ?? null) || ($type !== 'user' && $type !== 'department')) {
            return $found;
        }

        $found["{$type}:{$party['id']}"] ??= [
            'type' => $type,
            'id' => $party['id'],
            'code' => is_string($party['code'] ?? null) ? $party['code'] : null,
            'name' => is_string($party['label'] ?? null) ? $party['label'] : null,
            'affiliation' => is_string($party['affiliation'] ?? null) ? $party['affiliation'] : null,
        ];

        return $found;
    }

    /**
     * 書く値の見せ方(前の件を指す参照はそのまま、ほかは画面の初期値の形。複数の値は要素ごと)。
     */
    private static function next(mixed $value, mixed $shown): mixed
    {
        if (self::isReference($value)) {
            return $value;
        }

        if (! is_array($value) || ! array_is_list($value) || ! self::hasReference($value)) {
            return $shown;
        }

        $byId = is_array($shown) ? array_column(array_filter($shown, is_array(...)), null, 'id') : [];

        return array_map(
            fn (mixed $element): mixed => self::isReference($element) ? $element : ($byId[$element] ?? $element),
            $value,
        );
    }

    /**
     * 明細の 1 行(行の id と書く値)。
     *
     * @param  array<string, mixed>  $shown
     * @return array{id: int|null, values: array<string, mixed>}
     */
    private static function rowOf(mixed $row, array $shown): array
    {
        $id = is_array($row) && is_int($row['id'] ?? null) ? $row['id'] : null;

        return ['id' => $id, 'values' => self::rowNext(self::rowValues($row), $shown)];
    }

    /**
     * 明細の 1 行の書く値。
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<string, mixed>  $shown
     * @return array<string, mixed>
     */
    private static function rowNext(array $values, array $shown): array
    {
        $next = [];

        foreach ($values as $field => $value) {
            $next[(string) $field] = self::next($value, $shown[(string) $field] ?? null);
        }

        return $next;
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function hasReference(array $values): bool
    {
        return array_filter($values, self::isReference(...)) !== [];
    }

    private static function isReference(mixed $value): bool
    {
        return is_array($value) && array_keys($value) === ['ref'] && is_string($value['ref']);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function rowValues(mixed $row): array
    {
        return is_array($row) && is_array($row['values'] ?? null) ? $row['values'] : [];
    }
}
