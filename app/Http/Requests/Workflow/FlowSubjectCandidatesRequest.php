<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 定義の管理の対象者の候補 workflow.admin.subject-candidates の入力 { q }(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節):
 * q は 100 文字以内(無い・空なら候補は空。4 種類をまとめて引くので種類は受けない)。文言は認可設定の候補の入口と同じ。
 * 失敗は Laravel の既定の応答(JSON の要求なので 422)。
 */
class FlowSubjectCandidatesRequest extends FormRequest
{
    /** 語の最大の文字数 */
    public const MAX_LENGTH = 100;

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
}
