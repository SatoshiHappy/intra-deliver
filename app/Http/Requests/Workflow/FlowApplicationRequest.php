<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\CaseEntryGate;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * フローを指す書き込み(新規の申請 workflow.applications.store・新規の一時保存 workflow.drafts.store)の入力(docs の 設計/ワークフロー/画面.md 1.1・3 節)。
 * 封筒は {@see ReadsApplicationEnvelope}。改訂番号は無い(案件がまだ無い)。
 */
class FlowApplicationRequest extends FormRequest
{
    use ReadsApplicationEnvelope;

    /**
     * フローが無い 404 → 申請できる人でない 403 を、封筒の検証より前に確かめる(案件の操作/操作.md 3.1 の 1。例外をそのまま投げる)。
     *
     * @throws AuthorizationException 申請できる人でないとき
     */
    public function authorize(CaseEntryGate $gate): bool
    {
        $gate->authorizeFlow(AccessContext::of($this), (string) $this->route('flow'));

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [...$this->applicationEnvelopeRules(), ...$this->baseDateEnvelope()['rules']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [...$this->applicationEnvelopeMessages(), ...$this->baseDateEnvelope()['messages']];
    }
}
