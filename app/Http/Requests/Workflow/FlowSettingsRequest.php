<?php

namespace App\Http\Requests\Workflow;

/**
 * フローの機能設定の保存 workflow.admin.flows.settings.update の入力 { is_attachment_enabled: boolean }
 * (docs の 設計/ワークフロー/申請の周辺/画面.md の 4 節)。画面は必ず真偽を送るので、欠けた・真偽でない送りは壊れた送りだけ。
 */
class FlowSettingsRequest extends FlowDefinitionRequest
{
    /** 添付ファイルの使用の型の誤り(6 節に文が無い欄) */
    public const IS_ATTACHMENT_ENABLED_INVALID = '添付ファイルを使用するかどうかを選択してください';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['is_attachment_enabled' => ['bail', 'required', 'boolean']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['is_attachment_enabled' => self::IS_ATTACHMENT_ENABLED_INVALID];
    }

    public function isAttachmentEnabled(): bool
    {
        return $this->boolean('is_attachment_enabled');
    }
}
