<?php

namespace App\Http\Requests\Authz;

use App\Actions\Authz\AuthzCellInput;
use App\Enums\Authz\PolicyEffect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * セルの切替の入力(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2.2): 形だけを見る(相手の実在・種類とアクション・行は業務処理)。
 * resource_group_id・subject_group_id は正の整数、type・action は文字列、from・to は permit・deny・none(列挙に無い値は 422 そのキー)。
 * 最初に失敗した規則の文言だけを返す。
 */
class AuthzCellRequest extends FormRequest
{
    /** 未設定(継承・設定なし)を表す値 */
    public const NONE = 'none';

    /** 種類・アクションの ID の最大の文字数 */
    public const MAX_ID_LENGTH = 100;

    /**
     * @return array<string, list<string|In>>
     */
    public function rules(): array
    {
        $settings = Rule::in([PolicyEffect::Permit->value, PolicyEffect::Deny->value, self::NONE]);

        return [
            'resource_group_id' => ['bail', 'required', 'integer', 'min:1'],
            'subject_group_id' => ['bail', 'required', 'integer', 'min:1'],
            'type' => ['bail', 'required', 'string', 'max:'.self::MAX_ID_LENGTH],
            'action' => ['bail', 'required', 'string', 'max:'.self::MAX_ID_LENGTH],
            'from' => ['bail', 'required', 'string', $settings],
            'to' => ['bail', 'required', 'string', $settings],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resource_group_id' => '行の指定が正しくありません',
            'subject_group_id' => '列の指定が正しくありません',
            'type' => 'リソースの種類の指定が正しくありません',
            'action' => 'アクションの指定が正しくありません',
            'from' => '変更前の設定の指定が正しくありません',
            'to' => '変更後の設定の指定が正しくありません',
        ];
    }

    /**
     * 検証を通ったセル。
     */
    public function cell(): AuthzCellInput
    {
        return new AuthzCellInput(
            $this->integer('resource_group_id'),
            $this->integer('subject_group_id'),
            $this->string('type')->toString(),
            $this->string('action')->toString(),
        );
    }

    /**
     * 検証を通った from か to の直接の設定(none = null)。
     */
    public function effect(string $key): ?PolicyEffect
    {
        $value = $this->string($key)->toString();

        return $value === self::NONE ? null : PolicyEffect::from($value);
    }
}
