<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * 読み取り専用のページが URL のクエリから状態を読む口(docs の 設計/共通マスタ/検索と組織図.md 1.1、設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 1.4)。読めない値は「指定なし」と同じにし、
 * 検証のエラーにしない(戻る先の画面が無いため)。
 */
final class QueryParameters
{
    /**
     * クエリの値を整数として読む。無い・配列・整数として読めない値(int の範囲を超えるものを含む)は null。
     */
    public static function integer(Request $request, string $key): ?int
    {
        return self::integerOf(self::string($request, $key));
    }

    /**
     * 文字列を整数として読む。null・整数として読めない値(int の範囲を超えるものを含む)は null。
     */
    public static function integerOf(?string $value): ?int
    {
        return $value === null ? null : filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
    }

    /**
     * クエリの値を文字列として読む。無い・配列は null。
     */
    public static function string(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : null;
    }
}
