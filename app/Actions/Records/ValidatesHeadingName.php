<?php

namespace App\Actions\Records;

use App\Support\DefinitionText;

/**
 * 見出しの名前の確かめ(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 4 節。文言はその表のとおり)。
 * 入力の変換(前後の空白・空の扱い)は呼び手が先に行う({@see NormalizesInput})。
 */
trait ValidatesHeadingName
{
    /**
     * 見出しの名前の誤りの文言(無ければ null)。
     */
    private function headingNameError(mixed $name): ?string
    {
        return match (true) {
            $name === null => '見出しの名前を入力してください',
            ! is_string($name) || ! mb_check_encoding($name, 'UTF-8') => '見出しの名前は文字列で入力してください',
            str_contains($name, "\0") => '見出しの名前に使用できない文字(U+0000)が含まれています',
            mb_strlen($name) > DefinitionText::NAME_MAX => '見出しの名前は '.DefinitionText::NAME_MAX.' 文字までです',
            default => null,
        };
    }
}
