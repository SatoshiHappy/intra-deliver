<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\CaseEntryGate;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 再申請 workflow.reapplications.store の入力(docs の 設計/ワークフロー/画面.md 1.1・3 節)。封筒は {@see ReadsApplicationEnvelope}(全項目と明細の行の id)と改訂番号。
 */
class ReapplicationRequest extends FormRequest
{
    use ReadsApplicationEnvelope;
    use ReadsRevision;

    /**
     * 案件が無い・閲覧できない 404 → コンテンツの業務モジュールの update を許されない 403 を、封筒の検証より前に確かめる。
     *
     * @throws AuthorizationException update を許されないとき
     */
    public function authorize(CaseEntryGate $gate): bool
    {
        $gate->authorizeReapply(AccessContext::of($this), $this->caseId());

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [...$this->revisionRules(), ...$this->applicationEnvelopeRules()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->revisionMessages(), ...$this->applicationEnvelopeMessages()];
    }
}
