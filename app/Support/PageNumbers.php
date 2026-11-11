<?php

namespace App\Support;

use LogicException;

/**
 * 一覧のページの数え方の 1 か所(一覧と公開の口ごとに最後のページがずれないため)。DB を読まない(件数は呼び手が数えて渡す)。
 */
final class PageNumbers
{
    /**
     * 引くページの番号(クエリの page を読み、最後のページに詰める)。
     *
     * @param  mixed  $page  クエリの page
     * @param  int  $total  一覧の件数
     * @param  int<1, max>  $perPage  1 ページの件数
     */
    public static function of(mixed $page, int $total, int $perPage): int
    {
        return self::clamp(self::requested($page), $total, $perPage);
    }

    /**
     * クエリの page の読み方(読めなければ 1)。
     *
     * @param  mixed  $page  クエリの page
     */
    public static function requested(mixed $page): int
    {
        return is_string($page) && preg_match('/^[1-9][0-9]{0,8}$/D', $page) === 1 ? (int) $page : 1;
    }

    /**
     * 最後のページに詰めたページの番号。
     *
     * @param  int  $requested  引きたいページ
     * @param  int  $total  一覧の件数
     * @param  int<1, max>  $perPage  1 ページの件数
     */
    public static function clamp(int $requested, int $total, int $perPage): int
    {
        return max(1, min($requested, self::lastPage($total, $perPage)));
    }

    /**
     * 最後のページの番号(件数 0 なら 1)。1 ページの件数が整数の最大でもあふれない形で求める。
     *
     * @param  int  $total  一覧の件数
     * @param  int<1, max>  $perPage  1 ページの件数
     */
    public static function lastPage(int $total, int $perPage): int
    {
        return $total <= 0 ? 1 : intdiv($total - 1, $perPage) + 1;
    }

    /**
     * 1 ページの件数が 1 未満なら止める(呼び手の誤り。DB を読む前に止める)。
     *
     * @phpstan-assert int<1, max> $perPage
     *
     * @throws LogicException 1 未満のとき
     */
    public static function throwIfPerPageBelowOne(int $perPage): void
    {
        if ($perPage < 1) {
            throw new LogicException("1 ページの件数は 1 以上にする: {$perPage}");
        }
    }
}
