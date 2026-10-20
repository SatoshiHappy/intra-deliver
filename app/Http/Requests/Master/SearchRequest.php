<?php

namespace App\Http\Requests\Master;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 共通マスタの検索の入力(docs の 設計/共通マスタ/検索と組織図.md 2 節): q は任意の文字列で 100 文字以内。無い・空・空白だけ(前後の空白は既定のミドルウェア
 * TrimStrings が除き、空は ConvertEmptyStringsToNull が null にする)なら語で絞らない = 先頭から上限までを返す(参照の選択欄が開いた直後に呼ぶ。
 * ヘッダーの検索欄は語が空なら呼ばない)。失敗は Laravel の既定の応答(JSON の要求なので 422 と項目名 q のエラー)。
 */
class SearchRequest extends FormRequest
{
    /** 検索する語の最大の文字数 */
    public const MAX_LENGTH = 100;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['bail', 'nullable', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.string' => '検索する語の指定が正しくありません',
            'q.max' => '検索する語は '.self::MAX_LENGTH.' 文字以内にしてください',
        ];
    }

    /**
     * 検証を通った検索する語(無い・空なら空文字 = 絞らない)。
     */
    public function keyword(): string
    {
        return $this->string('q')->toString();
    }
}
