<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Workflow\CaseAttachmentRules;
use App\Queries\Workflow\CaseEntryGate;
use App\Support\AccessContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 一時保存の削除 workflow.drafts.destroy の入力 { revision }(本文で送る。docs の 設計/ワークフロー/画面/README.md 1.1・画面/申請と案件.md 6 節)。
 * 添付を受けない口なので、送られた attachments は黙って捨てず 422 にする(申請の周辺/添付/README.md の 3.1 節)。
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
        return [...$this->revisionRules(), 'attachments' => ['prohibited']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->revisionMessages(), 'attachments.prohibited' => CaseAttachmentRules::NOT_ATTACHABLE];
    }
}
