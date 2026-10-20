<?php

namespace UpdateCheck;

use JsonException;

/**
 * JSON の出力を読む。読めない・期待した鍵が無いときは「確認できなかった」(設計/更新チェック.md 5 節。コマンドの終了コードは判定に使わない)。
 */
final class Json
{
    /**
     * @return array<array-key, mixed>
     *
     * @throws UnconfirmedException
     */
    public static function parse(string $text, string $what): array
    {
        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UnconfirmedException("{$what} の出力が JSON として読めない");
        }

        if (! is_array($data)) {
            throw new UnconfirmedException("{$what} の出力の最上位が表(配列)でない");
        }

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     *
     * @throws UnconfirmedException
     */
    public static function table(array $data, string $key, string $what): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new UnconfirmedException("{$what} の出力に鍵 {$key} が無い(または表でない)");
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws UnconfirmedException
     */
    public static function string(array $data, string $key, string $what): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new UnconfirmedException("{$what} の出力に文字列の鍵 {$key} が無い");
        }

        return $value;
    }

    /**
     * 値が文字列(または数値)ならその文字列、それ以外は空文字。任意の項目を読むのに使う。
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function optionalString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
    }

    /**
     * 任意の表。鍵が無いか null なら null、あれば表でなければならない(型が違えば確認できなかった)。
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     *
     * @throws UnconfirmedException
     */
    public static function optionalTable(array $data, string $key, string $what): ?array
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new UnconfirmedException("{$what} の鍵 {$key} が表でない");
        }

        return $value;
    }

    /**
     * 任意の文字列。鍵が無いか null なら null、あれば文字列でなければならない。
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws UnconfirmedException
     */
    public static function nullableString(array $data, string $key, string $what): ?string
    {
        $value = $data[$key] ?? null;

        if ($value !== null && ! is_string($value)) {
            throw new UnconfirmedException("{$what} の鍵 {$key} が文字列でない");
        }

        return $value;
    }

    /**
     * 必須の真偽値。
     *
     * @param  array<array-key, mixed>  $data
     *
     * @throws UnconfirmedException
     */
    public static function boolean(array $data, string $key, string $what): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new UnconfirmedException("{$what} の鍵 {$key} が無い(または真偽値でない)");
        }

        return $value;
    }

    /**
     * 並びの要素がすべて表であることを確かめて返す(表でない要素を黙って除かない)。
     *
     * @param  array<array-key, mixed>  $list
     * @return list<array<array-key, mixed>>
     *
     * @throws UnconfirmedException
     */
    public static function tables(array $list, string $what): array
    {
        $tables = [];

        foreach ($list as $item) {
            if (! is_array($item)) {
                throw new UnconfirmedException("{$what} に表でない要素がある");
            }

            $tables[] = $item;
        }

        return $tables;
    }
}
