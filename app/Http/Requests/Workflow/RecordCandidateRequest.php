<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\ApplicationRecordCandidates;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 申請の画面の参照項目の候補の入力 { q, exclude, flow, case, applicant, record }(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節)。
 * 404 → 403 は {@see ApplicationRecordCandidates::requesterOf()} が検証より前に確かめる。q・exclude は業務データの入れ物の画面の候補の入口と同じ規則・文言
 * (設計/業務データの入れ物/画面/README.md 4 節)。flow・case・applicant・record は解釈できない値を誤りにせず読み取りが解釈する。
 *
 * @phpstan-import-type CandidateQuery from ApplicationRecordCandidates
 */
class RecordCandidateRequest extends FormRequest
{
    /** 語の最大の文字数(業務データの入れ物の画面の候補の入口と同じ) */
    public const MAX_LENGTH = 100;

    /**
     * 案件が無い・閲覧できない、フローが無い、フォームの参照先でないときは読み取りが 404 にする。
     *
     * @throws AuthorizationException 判定を求める者が参照先の read を許されないとき
     */
    public function authorize(ApplicationRecordCandidates $candidates): bool
    {
        $candidates->requesterOf(AccessContext::of($this), $this->moduleCode(), $this->formQuery());

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['bail', 'nullable', 'string', 'encoding:UTF-8', 'max:'.self::MAX_LENGTH],
            'exclude' => ['bail', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.string' => '検索する語の指定が正しくありません',
            'q.encoding' => '検索する語の指定が正しくありません',
            'q.max' => '検索する語は '.self::MAX_LENGTH.' 文字以内にしてください',
            'exclude.integer' => '除くレコードの指定が正しくありません',
            'exclude.min' => '除くレコードの指定が正しくありません',
        ];
    }

    /**
     * ルートの {module}(参照先の業務モジュールのコード)。
     */
    public function moduleCode(): string
    {
        return (string) $this->route('module');
    }

    /**
     * @return CandidateQuery
     */
    public function formQuery(): array
    {
        return [
            'flow' => $this->query('flow'),
            'case' => $this->query('case'),
            'applicant' => $this->query('applicant'),
            'record' => $this->query('record'),
        ];
    }

    /**
     * 検証を通った語(無い・空なら空文字 = 絞らない)。
     */
    public function keyword(): string
    {
        return $this->string('q')->toString();
    }

    /**
     * 検証を通った、候補から除くレコードの id(無い・空なら null = 除かない)。
     */
    public function excludedId(): ?int
    {
        return $this->filled('exclude') ? $this->integer('exclude') : null;
    }
}
