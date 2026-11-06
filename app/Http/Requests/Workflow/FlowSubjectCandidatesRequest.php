<?php

namespace App\Http\Requests\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * 定義の管理の対象者の候補 workflow.admin.subject-candidates の入力 { q, type }(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節):
 * q は 100 文字以内(無い・空なら候補は空)。type は任意で、あればその種類だけを引く(確認する人の候補 = type=user。
 * 設計/ワークフロー/案件の運用/定義の管理.md 2 節)。無ければ 4 種類をまとめて引く。文言は認可設定の候補の入口と同じ。
 * 失敗は Laravel の既定の応答(JSON の要求なので 422)。
 */
class FlowSubjectCandidatesRequest extends FormRequest
{
    /** 語の最大の文字数 */
    public const MAX_LENGTH = 100;

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            'q' => ['bail', 'nullable', 'string', 'encoding:UTF-8', 'max:'.self::MAX_LENGTH],
            'type' => ['bail', 'nullable', 'string', Rule::enum(SelectionConditionKind::class)],
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
            'type' => '種類の指定が正しくありません',
        ];
    }

    /**
     * 検証を通った種類(無ければ null = 4 種類)。
     */
    public function type(): ?SelectionConditionKind
    {
        return $this->enum('type', SelectionConditionKind::class);
    }

    /**
     * 検証を通った語(無い・空なら空文字)。
     */
    public function keyword(): string
    {
        return $this->string('q')->toString();
    }
}
