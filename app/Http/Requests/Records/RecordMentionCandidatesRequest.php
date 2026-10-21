<?php

namespace App\Http\Requests\Records;

use App\Queries\Records\RecordCommentGate;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * コメントの宛先の候補の入力(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 2 節・6 節)。判定は書く人と同じ
 * (閲覧できるユーザー)。空・空白だけの q は、既定のミドルウェアで null になるので「当たる人なし」として扱う。
 */
class RecordMentionCandidatesRequest extends FormRequest
{
    /**
     * @throws AuthorizationException 許されないとき(業務モジュール・レコードが無ければ 404 の例外が出る)
     */
    public function authorize(RecordCommentGate $gate): bool
    {
        $gate->authorize(AccessContext::of($this), (string) $this->route('module'), (int) $this->route('record'));

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['q' => ['bail', 'nullable', 'string', 'encoding:UTF-8', 'max:'.RecordCandidatesRequest::MAX_LENGTH]];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.string' => '検索する語の指定が正しくありません',
            'q.encoding' => '検索する語の指定が正しくありません',
            'q.max' => '検索する語は '.RecordCandidatesRequest::MAX_LENGTH.' 文字以内にしてください',
        ];
    }

    /**
     * 検証を通った語(無い・空なら空文字)。
     */
    public function keyword(): string
    {
        return $this->string('q')->toString();
    }
}
