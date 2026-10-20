<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\CaseEntryGate;
use App\Support\AccessContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 一時保存の削除 workflow.drafts.destroy の入力 { revision }(本文で送る。docs の 設計/ワークフロー/画面.md 1.1・6 節)。
 */
class DraftDeleteRequest extends FormRequest
{
    use ReadsRevision;

    /**
     * 案件が無い・閲覧できない 404 を、封筒の検証より前に確かめる(申請できる人を求めない = 申請できなくなった人も自分の一時保存を消せる。
     * 案件の操作/操作.md 3.1 の 1)。
     */
    public function authorize(CaseEntryGate $gate): bool
    {
        $gate->authorizeCase(AccessContext::of($this), $this->caseId());

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->revisionRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->revisionMessages();
    }
}
