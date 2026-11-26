<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\CaseEntryGate;
use App\Support\AccessContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 確認 workflow.cases.confirm の入力(値を受けない)。入口が見るのは添付を送っていないことだけ。
 */
class CaseConfirmationRequest extends FormRequest
{
    use RejectsAttachments;

    /**
     * 案件が無い・閲覧できない 404 を、添付の 422 より前に確かめる(確かめる人かの 403 は業務処理)。
     */
    public function authorize(CaseEntryGate $gate): bool
    {
        $gate->authorizeCase(AccessContext::of($this), (int) $this->route('case'));

        return true;
    }

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
