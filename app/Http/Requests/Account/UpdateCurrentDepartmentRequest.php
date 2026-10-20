<?php

namespace App\Http\Requests\Account;

use App\Queries\Master\MembershipQuery;
use App\Support\AccessContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * カレント組織の切替の入力(docs の 設計/デモユーザー切替/今の利用者.md 5.5): department_id は必須の整数で、判定の入力のユーザーと d の
 * カレント組織の候補({@see MembershipQuery::currentDepartmentCandidatesOn()})の組織であること。ゲストには候補が無い。
 * 失敗は Laravel の既定の応答(README の方針 7)。最初に失敗した規則の文言だけを返す。
 */
class UpdateCurrentDepartmentRequest extends FormRequest
{
    /**
     * @return array<string, list<string|In>>
     */
    public function rules(MembershipQuery $memberships): array
    {
        $context = AccessContext::of($this);
        $candidateIds = $context->userId === null
            ? []
            : $memberships->currentDepartmentCandidatesOn($context->userId, $context->date)->pluck('department_id')->all();

        return [
            'department_id' => ['bail', 'required', 'integer', Rule::in($candidateIds)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'department_id.required' => '所属を選択してください',
            'department_id.integer' => '所属の指定が正しくありません',
            'department_id.in' => '選択した組織は今日の所属にありません',
        ];
    }
}
