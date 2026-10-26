<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Workflow\ProxyInput;
use App\Actions\Workflow\ProxyRules;
use App\Support\AccessContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 代理の追加 workflow.proxies.store の入力 { proxy_user_id, kind, workflow_flow_code, start_date, end_date }(docs の
 * 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.9 節)。入口は形だけを見て、中身の規則と文言は業務処理({@see ProxyRules})が 422 にする。
 * 形の誤り(画面の欄からは起きない)は、その欄の業務処理の文言で返す(文言を足さないため)。ゲストは形より先に 403(同時実行と検査.md の 13 節)。
 */
class ProxyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AccessContext::of($this)->userId !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'proxy_user_id' => ['nullable', 'integer'],
            'kind' => ['nullable', 'string'],
            'workflow_flow_code' => ['nullable', 'string'],
            'start_date' => ['nullable', 'string'],
            'end_date' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proxy_user_id.integer' => ProxyRules::PROXY_REQUIRED,
            'kind.string' => ProxyRules::KIND_REQUIRED,
            'workflow_flow_code.string' => ProxyRules::FLOW_REQUIRED,
            'start_date.string' => ProxyRules::START_INVALID,
            'end_date.string' => ProxyRules::END_INVALID,
        ];
    }

    /**
     * 業務処理に渡す入力(規則が形を確かめた後に呼ぶ。終了日は最後の有効な日のまま渡す)。
     */
    public function proxyInput(): ProxyInput
    {
        return new ProxyInput(
            $this->input('proxy_user_id') === null ? null : $this->integer('proxy_user_id'),
            $this->text('kind'),
            $this->text('workflow_flow_code'),
            $this->text('start_date'),
            $this->text('end_date'),
        );
    }

    /**
     * 文字列の欄(無ければ null)。
     */
    private function text(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : null;
    }
}
