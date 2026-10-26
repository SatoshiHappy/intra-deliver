<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Workflow\ProxyRules;

/**
 * 代理の管理の追加 workflow.admin.proxies.store の入力(代理の設定の入力に代理元 principal_user_id を足したもの = docs の
 * 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.9 節)。システム管理者だけが通るのはルートの認可が決める。
 */
class AdminProxyRequest extends ProxyRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['principal_user_id' => ['nullable', 'integer'], ...parent::rules()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['principal_user_id.integer' => ProxyRules::PRINCIPAL_REQUIRED, ...parent::messages()];
    }

    /**
     * 代理元(無ければ null = 業務処理が「代理元を選択してください」にする。規則が形を確かめた後に呼ぶ)。
     */
    public function principalUserId(): ?int
    {
        return $this->input('principal_user_id') === null ? null : $this->integer('principal_user_id');
    }
}
