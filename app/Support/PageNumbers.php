<?php

namespace App\Support;

/**
 * 一覧のページ番号の 1 か所(案件・通知・業務データの一覧と、業務データの関連レコードの表が使う)。クエリの page は 1 から始まる 10 進の整数の文字列だけを読み(ほかは 1)、
 * 件数から求めた最後のページより大きければ最後のページに詰める(件数 0 なら 1)。DB を読まない(件数は呼び手が数えて渡す)。
 */
final class PageNumbers
{
    /**
     * 引くページの番号(読んで、最後のページに詰める)。
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
     * クエリの page の読み方(1 から始まる 10 進の整数の文字列だけ。ほかは 1)。
     *
     * @param  mixed  $page  クエリの page
     */
    public static function requested(mixed $page): int
    {
        return is_string($page) && preg_match('/^[1-9][0-9]{0,8}$/D', $page) === 1 ? (int) $page : 1;
    }

    /**
     * 最後のページに詰めたページの番号(件数 0 なら 1)。
     *
     * @param  int  $requested  引きたいページ
     * @param  int  $total  一覧の件数
     * @param  int<1, max>  $perPage  1 ページの件数
     */
    public static function clamp(int $requested, int $total, int $perPage): int
    {
        return max(1, min($requested, intdiv($total + $perPage - 1, $perPage)));
    }
}
