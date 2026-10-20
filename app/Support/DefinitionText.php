<?php

namespace App\Support;

/**
 * 定義(業務モジュール・項目・ワークフローのコンテンツ・ルート・フロー)のコード・名前・説明・並び順の規則と誤りの文言の 1 か所。業務データの入れ物と
 * ワークフローの定義の業務処理が使う(どちらのモジュールの公開の口でもないので共有に置く)。
 * 出典 = docs の 設計/業務データの入れ物/データモデル/ 1 節、設計/ワークフロー/データモデル/表.md 2.1、設計/共通マスタ/共通の規則.md 4 節。
 * 入力の変換(前後の空白・空の扱い)は持たない(呼び手が先に行う)。画面の入力時の検査(resources/js/lib/Records/recordDefinitionCode.ts)がコードの書式と文言を写している
 * (整合は tests/Architecture/RecordDefinitionCodeMirrorTest)。
 */
final class DefinitionText
{
    /** コード(業務モジュール・項目・選択肢)の最大の文字数(CODE_PATTERN と選択肢のコードの書式の長さ。画面の文字数の上限に渡す) */
    public const CODE_MAX = 50;

    /** コードの書式(長さは CODE_MAX から組み立てる) */
    public const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,'.(self::CODE_MAX - 1).'}$/D';

    /** 名前・文言の最大の文字数(varchar(1000)) */
    public const NAME_MAX = 1000;

    /** 説明の最大の文字数(varchar(4000)) */
    public const DESCRIPTION_MAX = 4000;

    /** ソートキーの絶対値の上限 */
    public const SORT_KEY_MAX = 999_999_999_999_999;

    /**
     * コード・名前・説明の最大の文字数(管理の画面の props。画面は数を持たない = docs の 設計/業務データの入れ物/画面/管理の画面.md 3 節の冒頭)。
     *
     * @return array{code: int, name: int, description: int}
     */
    public static function maxLengths(): array
    {
        return ['code' => self::CODE_MAX, 'name' => self::NAME_MAX, 'description' => self::DESCRIPTION_MAX];
    }

    /**
     * コードの書式の誤り(無ければ null。予約語と一意は呼び手が見る)。
     */
    public static function codeError(mixed $code): ?string
    {
        return match (true) {
            $code === null => 'コードを入力してください',
            ! is_string($code) || preg_match(self::CODE_PATTERN, $code) !== 1 => 'コードは英小文字で始まる英小文字・数字・_ の 50 文字までにしてください',
            default => null,
        };
    }

    /**
     * 名前・文言の誤り(無ければ null)。
     */
    public static function textError(mixed $text, string $label, bool $isRequired = true, int $max = self::NAME_MAX): ?string
    {
        return match (true) {
            $text === null => $isRequired ? "{$label}を入力してください" : null,
            ! is_string($text) || ! mb_check_encoding($text, 'UTF-8') => "{$label}は文字列で入力してください",
            str_contains($text, "\0") => "{$label}に使用できない文字(U+0000)が含まれています",
            mb_strlen($text) > $max => "{$label}は {$max} 文字以内で入力してください",
            default => null,
        };
    }

    /**
     * 説明の誤り(無ければ null。空 = null は誤りでない)。
     */
    public static function descriptionError(mixed $description): ?string
    {
        return self::textError($description, '説明', false, self::DESCRIPTION_MAX);
    }

    /**
     * 並び順の誤り(無ければ null。null は誤りでない = 呼び手が未指定として扱う)。
     */
    public static function sortKeyError(mixed $sortKey): ?string
    {
        return $sortKey === null || (is_int($sortKey) && abs($sortKey) <= self::SORT_KEY_MAX) ? null : '並び順は 15 桁までの整数で入力してください';
    }
}
