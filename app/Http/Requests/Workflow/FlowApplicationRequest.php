<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\CaseEntryGate;
use App\Support\AccessContext;
use App\Support\PositiveIds;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * フローを指す書き込み(新規の申請 workflow.applications.store・新規の一時保存 workflow.drafts.store)の入力
 * (docs の 設計/ワークフロー/画面/README.md 1.1・画面/申請と案件.md 3 節)。
 * 封筒は {@see ReadsApplicationEnvelope}。改訂番号は無い(案件がまだ無い)。添付は受け、外す指定は外す相手が無いので捨てる。
 * 申請のルートだけが record_id・record_version を受け(既存のデータからの申請 = 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 4 節)、
 * 一時保存のルートは捨てる(一時保存はレコードを持てない)。
 */
class FlowApplicationRequest extends FormRequest
{
    use ReadsApplicationEnvelope;

    /** 既存のデータからの申請を受けるルート */
    private const APPLY_ROUTE = 'workflow.applications.store';

    /** レコードの id の桁の上限(レコードの詳細のルートの {record} と同じ。申請の画面のクエリ record も同じ上限で読む) */
    public const RECORD_ID_MAX_DIGITS = PositiveIds::MAX_DIGITS;

    private const RECORD_ID_RULES = ['nullable', 'integer', 'min:1', 'max_digits:'.self::RECORD_ID_MAX_DIGITS];

    /**
     * フローが無い 404 → 申請できる人でない 403 を、封筒の検証より前に確かめる(案件の操作/操作.md 3.1 の 1。例外をそのまま投げる)。
     * 既存のデータからの申請は 403 を自分か代理元のどれかで見る。
     *
     * @throws AuthorizationException 申請できる人でないとき
     */
    public function authorize(CaseEntryGate $gate): bool
    {
        $context = AccessContext::of($this);
        $flowCode = (string) $this->route('flow');

        if ($this->fromRecord()) {
            $gate->authorizeRecordApply($context, $flowCode);
        } else {
            $gate->authorizeFlow($context, $flowCode);
        }

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->withAttachmentRules([
            ...$this->applicationEnvelopeRules(),
            ...$this->baseDateEnvelope()['rules'],
            ...($this->routeIs(self::APPLY_ROUTE) ? ['record_id' => self::RECORD_ID_RULES] : []),
            // 版の形は業務データの入れ物の更新の入口と同じ(updated_at の無い行の版 = 空文字は null で届く)
            ...($this->fromRecord() ? ['record_version' => ['present', 'nullable', 'string']] : []),
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->withAttachmentMessages([
            ...$this->applicationEnvelopeMessages(),
            ...$this->baseDateEnvelope()['messages'],
            'record_id.integer' => 'レコードの指定が正しくありません',
            'record_id.min' => 'レコードの指定が正しくありません',
            'record_id.max_digits' => 'レコードの指定が正しくありません',
            'record_version.present' => '版の指定がありません',
            'record_version.string' => '版の指定が正しくありません',
        ]);
    }

    /**
     * 既存のデータからの申請のレコード(申請のルートだけ。無ければ新規の申請。規則が integer・min:1・桁の上限を確かめた後に呼ぶ)。
     */
    public function recordId(): ?int
    {
        return $this->fromRecord() ? $this->integer('record_id') : null;
    }

    /**
     * 開いたときの版(null は空文字。業務処理が今の版と比べる)。
     */
    public function recordVersion(): string
    {
        return $this->string('record_version')->toString();
    }

    protected function acceptsAttachmentRemovals(): bool
    {
        return false;
    }

    private function fromRecord(): bool
    {
        return $this->routeIs(self::APPLY_ROUTE) && $this->input('record_id') !== null;
    }
}
