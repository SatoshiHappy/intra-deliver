<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\FlowDefinitionErrors;
use Closure;

/**
 * 参照者の保存 workflow.admin.flows.referrers.update の入力 { referrers: { type, id }[] }
 * (docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 5.2 節)。
 * 並びが無い・並びでないときは referrers に対象者の行の文(画面は必ず並びを送るので、壊れた送りでしか出ない)。0 行 = 参照者なし。
 */
class FlowReferrersRequest extends FlowDefinitionRequest
{
    use ReadsSubjectRows;

    /**
     * @return array<string, list<string|Closure>>
     */
    public function rules(): array
    {
        return ['referrers' => ['bail', 'present', 'list'], 'referrers.*' => $this->subjectRowRules()];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $missing = FlowDefinitionErrors::SUBJECT_MISSING;

        return ['referrers' => $missing, ...$this->subjectRowMessages('referrers')];
    }

    /**
     * @return list<SelectionCondition>
     */
    public function referrers(): array
    {
        return $this->subjectConditions('referrers');
    }
}
