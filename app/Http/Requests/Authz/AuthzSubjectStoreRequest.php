<?php

namespace App\Http\Requests\Authz;

use App\Enums\Authz\SubjectColumnKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * 「列を追加」の入力(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.1): kind は user・role・department・post、target_id は正の整数。
 * 相手の実在・有効と同じ条件の列は業務処理が確かめる。最初に失敗した規則の文言だけを返す。
 */
class AuthzSubjectStoreRequest extends FormRequest
{
    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'kind' => ['bail', 'required', 'string', Rule::enum(SubjectColumnKind::class)],
            'target_id' => ['bail', 'required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kind' => '種類の指定が正しくありません',
            'target_id.required' => '追加する相手を選択してください',
            'target_id' => '追加する相手の指定が正しくありません',
        ];
    }

    public function kind(): SubjectColumnKind
    {
        return SubjectColumnKind::from($this->string('kind')->toString());
    }

    public function targetId(): int
    {
        return $this->integer('target_id');
    }
}
