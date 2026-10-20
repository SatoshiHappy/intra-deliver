<?php

namespace App\Actions\Records;

use App\Support\InputText;

/**
 * 業務処理が受けた入力の文字列を、型を問わず前後の空白を除き、除いた後の空文字を null にする(docs の 設計/業務データの入れ物/項目と規則/README.md 2 節の前文。
 * 入口の TrimStrings・ConvertEmptyStringsToNull と同じ変換を、呼び手に依らないように業務処理でも行う)。配列は中まで変換する。変換の本体は共有の {@see InputText}。
 */
trait NormalizesInput
{
    /**
     * 入力の配列(業務処理が受けた形のまま)の値を中まで変換する。配列を受けて配列を返す。
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    private function normalizeInput(array $input): array
    {
        return array_map(fn (mixed $item) => $this->normalizeValue($item), $input);
    }

    /**
     * 1 つの値の変換(文字列は前後の空白を除いて空なら null、配列は中まで、ほかはそのまま。本体は {@see InputText::normalize()})。
     */
    private function normalizeValue(mixed $value): mixed
    {
        return InputText::normalize($value);
    }
}
