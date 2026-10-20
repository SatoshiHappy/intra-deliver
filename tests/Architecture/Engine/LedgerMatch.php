<?php

namespace Tests\Architecture\Engine;

/**
 * 台帳と実体の照合(汎用)。要素は文字列で、順序と重複は照合に影響しない。
 *
 * - 許容集合型 = {@see self::subset()}(実体 ⊆ 台帳。台帳にあって実体に無いものは違反にしない)
 * - 存在型 = {@see self::equal()}(双方向。無断追加と水増しの両方を差分にする)
 */
final class LedgerMatch
{
    /**
     * 実体が許容集合に収まるかを照合する。差分の extra だけが埋まる。
     *
     * @param  list<string>  $actual
     * @param  list<string>  $allowed
     */
    public static function subset(array $actual, array $allowed): LedgerDifference
    {
        return new LedgerDifference(self::minus($actual, $allowed), []);
    }

    /**
     * 実体と台帳が一致するかを照合する。
     *
     * @param  list<string>  $actual
     * @param  list<string>  $ledger
     */
    public static function equal(array $actual, array $ledger): LedgerDifference
    {
        return new LedgerDifference(self::minus($actual, $ledger), self::minus($ledger, $actual));
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private static function minus(array $left, array $right): array
    {
        $difference = array_values(array_unique(array_diff($left, $right)));
        sort($difference, SORT_STRING);

        return $difference;
    }
}
