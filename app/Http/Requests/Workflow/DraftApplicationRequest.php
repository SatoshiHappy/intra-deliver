<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\CaseEntryGate;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 一時保存を指す書き込み(上書き workflow.drafts.update・一時保存からの申請 workflow.drafts.apply)の入力
 * (docs の 設計/ワークフロー/画面/README.md 1.1・画面/申請と案件.md 3 節)。
 * 封筒は {@see ReadsApplicationEnvelope} と改訂番号。一時保存でない案件を指すときは、入口でなく業務処理が 422 case にする(案件の操作/操作.md 3.1 の 1)。
 * 添付と、一時保存の添付を外す指定(remove_attachment_ids)を受ける。
 */
class DraftApplicationRequest extends FormRequest
{
    use ReadsApplicationEnvelope;
    use ReadsRevision;

    /**
     * 案件が無い・閲覧できない 404 → 申請できる人でない 403 を、封筒の検証より前に確かめる。
     *
     * @throws AuthorizationException 申請できる人でないとき
     */
    public function authorize(CaseEntryGate $gate): bool
    {
        $gate->authorizeDraftWrite(AccessContext::of($this), $this->caseId());

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->withAttachmentRules([
            ...$this->revisionRules(),
            ...$this->applicationEnvelopeRules(),
            ...$this->baseDateEnvelope()['rules'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->withAttachmentMessages([...$this->revisionMessages(), ...$this->applicationEnvelopeMessages(), ...$this->baseDateEnvelope()['messages']]);
    }

    protected function acceptsAttachmentRemovals(): bool
    {
        return true;
    }
}
