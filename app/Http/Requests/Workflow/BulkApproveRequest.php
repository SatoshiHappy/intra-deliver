<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Workflow\BulkApproveCases;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 一括承認の入力 { cases, comment }(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節)。cases の並び・件数・形・重複は
 * 業務処理 {@see BulkApproveCases} が確かめるので、ここでは受けたまま渡す。コメントの形だけは単独の処理の入力と同じく入口が見る。
 */
class BulkApproveRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'comment' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comment.string' => 'コメントの形が正しくありません',
        ];
    }

    /**
     * 入力 cases(受けたまま)。
     */
    public function cases(): mixed
    {
        return $this->input('cases');
    }

    public function comment(): ?string
    {
        $comment = $this->input('comment');

        return is_string($comment) ? $comment : null;
    }
}
