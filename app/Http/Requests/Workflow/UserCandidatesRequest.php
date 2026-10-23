<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\UserCandidates;
use App\Support\AccessContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 人の候補の入力 { q, case, on }(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節・1.3 節)。case の 404 は
 * {@see UserCandidates::authorize()} が検証より前に確かめ、その後に q(任意の文字列で 100 文字以内。空なら候補は空)を検証する
 * (処理対象者の候補の入口 {@see AssigneeCandidatesRequest} と同じ形)。on は読めなければ今日として読み取りが解釈する。
 */
class UserCandidatesRequest extends FormRequest
{
    /** 語の最大の文字数 */
    public const MAX_LENGTH = 100;

    /**
     * 案件が無い・閲覧できないときは読み取りが 404 にする。
     */
    public function authorize(UserCandidates $candidates): bool
    {
        $candidates->authorize(AccessContext::of($this), $this->caseId());

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['bail', 'nullable', 'string', 'encoding:UTF-8', 'max:'.self::MAX_LENGTH],
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
        ];
    }

    /**
     * 検証を通った語(無い・空なら空文字)。
     */
    public function keyword(): string
    {
        return $this->string('q')->toString();
    }

    /**
     * クエリの case(無ければ null。読めるかは読み取りが確かめる)。
     */
    public function caseId(): mixed
    {
        return $this->query('case');
    }

    /**
     * クエリの on(無ければ null)。
     */
    public function on(): mixed
    {
        return $this->query('on');
    }
}
