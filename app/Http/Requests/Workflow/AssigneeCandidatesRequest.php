<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\AssigneeCandidates;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 処理対象者の候補の入力 { node, q }(docs の 設計/ワークフロー/ルートの深さ/画面.md 1.1、案件の操作/処理対象者.md 5.2)。404 → 422 case → 403 は
 * {@see AssigneeCandidates::authorize()} が検証より前に確かめ、その後に q(任意の文字列で 100 文字以内。空なら候補は空)を検証する。
 */
class AssigneeCandidatesRequest extends FormRequest
{
    /** 語の最大の文字数 */
    public const MAX_LENGTH = 100;

    /**
     * @throws AuthorizationException 今の利用者が指定するノードを承認できないとき
     */
    public function authorize(AssigneeCandidates $candidates): bool
    {
        $candidates->authorize(AccessContext::of($this), $this->caseId(), $this->query('node'));

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

    public function caseId(): int
    {
        return (int) $this->route('case');
    }

    /**
     * 指定されるノード(authorize が整数として読めることを確かめた後に呼ぶ)。
     */
    public function nodeId(): int
    {
        return (int) $this->query('node');
    }
}
