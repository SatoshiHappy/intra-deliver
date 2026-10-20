<?php

namespace App\Http\Requests\Records;

use App\Enums\Records\RecordModuleAction;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordModuleGate;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * レコードの登録・更新の入力の封筒(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.1、画面/業務の画面.md 2.2)。封筒の規則・文言・入力への変換は
 * {@see ReadsRecordEnvelope}(ワークフローの申請の入口と同じ 1 か所)。値の前後の空白と空文字は既定のミドルウェア(TrimStrings・
 * ConvertEmptyStringsToNull)が除き、業務処理も同じ変換をする(項目と規則/README.md 2 節)。
 */
class RecordInputRequest extends FormRequest
{
    use ReadsRecordEnvelope;

    /**
     * 口の順(404 → 403 → レコードの 404)を検証より前に確かめる(認可とメニュー/README.md 2.2)。登録は create、更新は update。
     * 404 は ModelNotFoundException、403 は AuthorizationException をそのまま投げる(false を返すと文言の無い 403 になるため)。
     *
     * @throws AuthorizationException 許されないとき
     */
    public function authorize(RecordModuleGate $gate): bool
    {
        $context = AccessContext::of($this);
        $record = $this->route('record');
        $gate->authorizeTarget(
            Requester::fromAccessContext($context),
            (string) $this->route('module'),
            is_string($record) ? RecordModuleAction::Update : RecordModuleAction::Create,
            $context->date,
            is_string($record) ? (int) $record : null,
        );

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return $this->recordEnvelopeRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->recordEnvelopeMessages();
    }
}
