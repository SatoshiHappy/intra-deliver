<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * 業務処理が受けた入力の文字列の変換の 1 か所: 前後の空白を除き、除いた後の空文字を null にする。配列は中まで変換する(docs の
 * 設計/業務データの入れ物/項目と規則/README.md 2 節の前文。入口の TrimStrings・ConvertEmptyStringsToNull と同じ変換を、呼び手に依らないように業務処理でも行う)。
 * 前後の空白は Str::trim の既定の文字集合(U+0000 を含む)なので、前後の U+0000 も除かれる(残るのは中の U+0000 だけ)。
 * 使い手 = 業務データの入れ物の入力(NormalizesInput・RecordConditions)とワークフローの案件の入力(CaseInputRules・DraftInput・ApplicationBaseDate・ApplicationBaseDates)と定義の入力
 * (ValidatesWorkflowDefinition = コンテンツ・フロー・ルートのコード・名前・説明)。どのモジュールの公開の口でもないので共有に置く。
 */
final class InputText
{
    /**
     * 1 つの値の変換(文字列は前後の空白を除いて空なら null、配列は中まで、ほかはそのまま)。
     *
     * @return ($value is array ? array<array-key, mixed> : ($value is string ? string|null : mixed))
     */
    public static function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item) => self::normalize($item), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        $trimmed = Str::trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
