<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 一括確認の入力 { case_ids }。並び・件数・形は業務処理が確かめるので、入口が見るのは添付を送っていないことだけ。
 */
class BulkConfirmRequest extends FormRequest
{
    use RejectsAttachments;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->attachmentRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->attachmentMessages();
    }
}
