<?php

namespace App\Http\Requests\Authz;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 「列を削除」の入力(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.2): 確かめのダイアログが見た件数 count(0 以上の整数)と
 * 指紋 fingerprint(SHA-256 の 16 進の 64 文字)。取り直した値との比べは業務処理が行う。最初に失敗した規則の文言だけを返す。
 */
class AuthzSubjectDestroyRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'count' => ['bail', 'required', 'integer', 'min:0'],
            'fingerprint' => ['bail', 'required', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'count' => '件数の指定が正しくありません',
            'fingerprint' => '設定の確かめの値が正しくありません',
        ];
    }

    public function policyCount(): int
    {
        return $this->integer('count');
    }

    public function fingerprint(): string
    {
        return $this->string('fingerprint')->toString();
    }
}
