<?php

namespace App\Actions\Ai\ToolCalls;

/**
 * 書く時の、前の件を指す印(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5 の件どうしのつながりの「書く時の印」)。印の置き場は
 * 項目の値と明細の行の値の、値か列の要素({@see ToolCallLinks::refName()} の形。呼ばれた時に他モジュール参照の項目だけにあると確かめてある)。
 * 道具のクラスを呼ぶ前に、指した件で作ったレコードの id を入れる(確認の内容の印は変えない)。
 */
final class ToolCallRefs
{
    /**
     * 書く値の引数が指す仮の名前。
     *
     * @param  array<string, mixed>  $written
     * @return list<string>
     */
    public static function names(array $written): array
    {
        $names = [];
        self::walk($written, function (string $name) use (&$names): int {
            $names[] = $name;

            return 0;
        });

        return array_values(array_unique($names));
    }

    /**
     * 印に作った id を入れた引数。
     *
     * @param  array<string, mixed>  $written
     * @param  array<string, int>  $ids  仮の名前 → 作ったレコードの id(指した名前はどれもある)
     * @return array<string, mixed>
     */
    public static function filled(array $written, array $ids): array
    {
        return self::walk($written, fn (string $name): int => $ids[$name]);
    }

    /**
     * 項目の値と明細の行の値の印を $replace の値に替える。
     *
     * @param  array<string, mixed>  $written
     * @param  callable(string): int  $replace
     * @return array<string, mixed>
     */
    private static function walk(array $written, callable $replace): array
    {
        if (is_array($written['values'] ?? null)) {
            $written['values'] = self::values($written['values'], $replace);
        }

        if (is_array($written['details'] ?? null)) {
            $rows = fn (mixed $rows): mixed => is_array($rows)
                ? array_map(fn (mixed $row): mixed => self::row($row, $replace), $rows)
                : $rows;
            $written['details'] = array_map($rows, $written['details']);
        }

        return $written;
    }

    /**
     * @param  callable(string): int  $replace
     */
    private static function row(mixed $row, callable $replace): mixed
    {
        if (is_array($row) && is_array($row['values'] ?? null)) {
            $row['values'] = self::values($row['values'], $replace);
        }

        return $row;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  callable(string): int  $replace
     * @return array<array-key, mixed>
     */
    private static function values(array $values, callable $replace): array
    {
        return array_map(
            fn (mixed $value): mixed => is_array($value) && array_is_list($value)
                ? array_map(fn (mixed $element): mixed => self::replaced($element, $replace), $value)
                : self::replaced($value, $replace),
            $values,
        );
    }

    /**
     * @param  callable(string): int  $replace
     */
    private static function replaced(mixed $value, callable $replace): mixed
    {
        $name = ToolCallLinks::refName($value);

        return $name === null ? $value : $replace($name);
    }
}
