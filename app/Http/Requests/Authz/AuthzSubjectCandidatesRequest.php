<?php

namespace App\Http\Requests\Authz;

use App\Enums\Authz\SubjectColumnKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * 「列を追加」の候補の入力 { kind, q }(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.1): kind は user・role・department・post、
 * q は任意の文字列で 100 文字以内(無い・空なら候補は空)。失敗は Laravel の既定の応答(JSON の要求なので 422)。
 */
class AuthzSubjectCandidatesRequest extends FormRequest
{
    /** 語の最大の文字数 */
    public const MAX_LENGTH = 100;

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['bail', 'required', 'string', Rule::enum(SubjectColumnKind::class)],
            'q' => ['bail', 'nullable', 'string', 'max:'.self::MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind' => '種類の指定が正しくありません',
            'q.string' => '検索する語の指定が正しくありません',
            'q.max' => '検索する語は '.self::MAX_LENGTH.' 文字以内にしてください',
        ];
    }

    public function kind(): SubjectColumnKind
    {
        return SubjectColumnKind::from($this->string('kind')->toString());
    }

    /**
     * 検証を通った語(無い・空なら空文字)。
     */
    public function keyword(): string
    {
        return $this->string('q')->toString();
    }
}
