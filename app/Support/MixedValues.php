<?php

namespace App\Support;

/**
 * 型の決まらない値(JSON の入力・画面の値の配列)を読むときの、型が合わなければ空にする読み方。
 * 形の保証が呼び手の外(定義との照合)にある値だけに使う。
 */
final class MixedValues
{
    /**
     * @return array<array-key, mixed>
     */
    public static function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public static function stringOf(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    public static function intOf(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
