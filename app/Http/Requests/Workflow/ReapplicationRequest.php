<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\CaseEntryGate;
use App\Support\AccessContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * 再申請 workflow.reapplications.store の入力(docs の 設計/ワークフロー/画面/README.md 1.1・画面/申請と案件.md 3 節)。封筒は {@see ReadsApplicationEnvelope}(全項目と明細の行の id)と改訂番号。
 * 添付は受け、外す指定は捨てる(申請した後の添付は申請の中で外せない = 申請の周辺/添付/README.md の 2 節)。処理担当者と連絡は {@see ReadsPrincipalAndContact}。
 */
class ReapplicationRequest extends FormRequest
{
    use ReadsApplicationEnvelope {
        after as private envelopeAfter;
    }
    use ReadsPrincipalAndContact;
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
        return $this->withAttachmentRules([
            ...$this->revisionRules(), ...$this->applicationEnvelopeRules(), ...$this->principalAndContactRules(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->withAttachmentMessages([
            ...$this->revisionMessages(), ...$this->applicationEnvelopeMessages(),
            ...$this->principalAndContactMessages(),
        ]);
    }

    /**
     * 検証の後のフック(封筒の印の誤りと連絡する人の形)。
     *
     * @return array<Closure(Validator): void>
     */
    public function after(): array
    {
        return [...$this->envelopeAfter(), $this->contactShapeCheck()];
    }

    protected function acceptsAttachmentRemovals(): bool
    {
        return false;
    }
}
