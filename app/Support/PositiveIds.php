<?php

namespace App\Support;

/**
 * クエリの識別子の読み方(正の整数の 10 進の文字列だけ。ほかは null)。読めない値は無いのと同じにして、検証の誤りにしない
 * (docs の 設計/ワークフロー/認可と一覧.md の 3.2 節のクエリの規則)。
 */
final class PositiveIds
{
    /** 識別子の桁数の上限(bigint の範囲に収まる 18 桁) */
    public const MAX_DIGITS = 18;

    /**
     * @param  mixed  $value  クエリの値
     */
    public static function of(mixed $value): ?int
    {
        $pattern = '/^[1-9][0-9]{0,'.(self::MAX_DIGITS - 1).'}$/D';

        return is_string($value) && preg_match($pattern, $value) === 1 ? (int) $value : null;
    }
}
