<?php

namespace App\Actions\Records;

/**
 * 並び替えの入力が、今の並びのちょうど並べ替え(順列)かの確かめ(項目の並び替え = 項目と規則/規則と業務処理/失敗と定義の変更.md 11 節、モジュール分類と業務モジュールの
 * 並び替え = モジュール分類/業務処理.md 3.5・3.6)。
 */
trait ChecksPermutation
{
    /**
     * 渡された並びが、今の値のちょうど並べ替えなら、その並び(過不足・重複・文字列でない要素があれば null)。
     *
     * @param  array<array-key, mixed>  $given
     * @param  array<array-key, int|string>  $current  今の値
     * @return list<string>|null
     */
    private function permutation(array $given, array $current): ?array
    {
        $strings = array_values(array_filter($given, fn (mixed $code) => is_string($code)));

        if (count($strings) !== count($given) || count(array_unique($strings)) !== count($given)) {
            return null;
        }

        $sorted = $strings;
        $current = array_map(strval(...), $current);
        sort($sorted, SORT_STRING);
        sort($current, SORT_STRING);

        return $sorted === $current ? $strings : null;
    }
}
