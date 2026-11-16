<?php

namespace App\Actions\Ai\Tools;

/**
 * 探すの、口が黙って外した物(docs の 設計/AI/道具と権限/README.md 10.2 の探す)。モデルが引数を直せるように、外した条件・並び・項目を返す。
 *
 * 範囲の型(実行の始めの定義の型が {@see RecordToolValues::ORDERED_TYPES})の条件の行の結果:
 *
 * | 条件の値 | 外した物 |
 * |---|---|
 * | オブジェクトでない(文字列・数・列)・空のオブジェクト・`from` も `to` も持たないオブジェクト | `<鍵>` だけ(ほかの鍵も出さない) |
 * | `from` か `to` を持つオブジェクトの、送って読めた側 | 出さない(当てる) |
 * | 同じく、送って読めない側(片側だけ読めない) | `<鍵>.from`・`<鍵>.to` |
 * | 同じく、両側とも送って読めない | `<鍵>` |
 * | 同じく、送らない側 | 出さない |
 * | 同じく、`from`・`to` のほかの鍵 | `<鍵>.<その鍵>` |
 *
 * 範囲の型でない条件は、口が当てなかった鍵を `<鍵>` で出す(ユーザー・組織の名前の物は、③ が先に id に直す。決まらなければ道具に来ない)。
 */
final class SearchIgnored
{
    private const SIDES = ['from', 'to'];

    /**
     * @param  array<array-key, mixed>  $conditions  渡した条件
     * @param  array<string, string>  $types  実行の始めの定義の項目のコード → 型
     * @param  list<string>|null  $fields  渡した項目
     * @param  array{filters: array<string, mixed>, sort: string, fields: list<string>}  $query  口が当てた条件・並び・項目
     * @return array{ignored?: array<string, list<string>|string>}
     */
    public static function of(array $conditions, array $types, ?string $sort, ?array $fields, array $query): array
    {
        $ignoredConditions = [];

        foreach ($conditions as $key => $value) {
            $key = (string) $key;
            $applied = $query['filters'][$key] ?? null;
            $isRange = in_array($types[$key] ?? null, RecordToolValues::ORDERED_TYPES, true);
            $ignoredConditions = [...$ignoredConditions, ...match (true) {
                $isRange => self::range($key, $value, $applied),
                $applied === null => [$key],
                default => [],
            }];
        }

        $ignored = array_filter([
            'conditions' => $ignoredConditions,
            'sort' => $sort !== null && $sort !== $query['sort'] ? $sort : null,
            'fields' => array_values(array_diff($fields ?? [], $query['fields'])),
        ], fn (array|string|null $value): bool => $value !== null && $value !== []);

        return $ignored === [] ? [] : ['ignored' => $ignored];
    }

    /**
     * 範囲の条件 1 つの外した物(上の表)。
     *
     * @return list<string>
     */
    private static function range(string $key, mixed $value, mixed $applied): array
    {
        $isObject = is_array($value) && $value !== [] && ! array_is_list($value);
        $keys = $isObject ? array_map(strval(...), array_keys($value)) : [];
        $sent = array_values(array_intersect(self::SIDES, $keys));

        if ($sent === []) {
            return [$key];
        }

        $read = is_array($applied) ? $applied : [];
        $unread = array_values(array_filter($sent, fn (string $side): bool => ($read[$side] ?? null) === null));
        $sides = count($unread) === count(self::SIDES)
            ? [$key]
            : array_map(fn (string $side): string => "{$key}.{$side}", $unread);
        $others = array_map(
            fn (string $other): string => "{$key}.{$other}",
            array_values(array_diff($keys, self::SIDES)),
        );

        return [...$sides, ...$others];
    }
}
