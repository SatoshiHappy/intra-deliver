<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Workflow\FlowDefinitionErrors;
use App\Actions\Workflow\NodeSettingsInput;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * ステップの設定の保存 workflow.admin.nodes.update の入力(欄 = {@see NodeSettingsInput} の snake_case。docs の
 * 設計/ワークフロー/ルートの深さ/定義の管理.md 5.1 節)。入口は型だけを見て(欠けた欄と null は空)、種類で決まる必須・禁止と値の規則は業務処理が
 * 422 にする。型の誤りは、その欄の 6 節の文(選択の欄は「選択してください」の文)で返す。
 */
class FlowNodeSettingsRequest extends FlowDefinitionRequest
{
    use ReadsSubjectRows;

    /** 申請者本人の承認を防ぐの型の誤り(6 節に文が無い欄。画面は必ず真偽を送るので、壊れた送りでしか出ない) */
    public const PREVENTS_APPLICANT_APPROVAL_INVALID = '申請者本人の承認を防ぐ設定を選択してください';

    /**
     * @return array<string, list<string|Closure|Enum>>
     */
    public function rules(): array
    {
        $integer = ['bail', 'nullable', 'integer'];

        return [
            'target_kind' => self::enumRules(WorkflowTargetKind::class),
            'targets' => ['bail', 'nullable', 'list'],
            'targets.*' => $this->subjectRowRules(),
            'relative_base' => self::enumRules(WorkflowRelativeBase::class),
            'relative_scope' => self::enumRules(WorkflowRelativeScope::class),
            'company_post_id' => $integer,
            'designator_node_code' => ['bail', 'nullable', 'string'],
            'min_assignees' => $integer,
            'max_assignees' => $integer,
            'prevents_applicant_approval' => ['bail', 'nullable', 'boolean'],
            'send_back_node_codes' => ['bail', 'nullable', 'list', self::codesRule()],
            'decision_rule' => self::enumRules(WorkflowDecisionRule::class),
            'decision_value' => $integer,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $decisionValue = $this->input('decision_rule') === WorkflowDecisionRule::Ratio->value
            ? FlowDefinitionErrors::DECISION_RATIO_RANGE : FlowDefinitionErrors::DECISION_COUNT_RANGE;

        return [
            'target_kind' => FlowDefinitionErrors::TARGET_KIND_REQUIRED,
            'targets' => FlowDefinitionErrors::TARGETS_REQUIRED,
            ...$this->subjectRowMessages('targets'),
            'relative_base' => FlowDefinitionErrors::RELATIVE_BASE_REQUIRED,
            'relative_scope' => FlowDefinitionErrors::RELATIVE_SCOPE_REQUIRED,
            'company_post_id' => FlowDefinitionErrors::COMPANY_POST_MISSING,
            'designator_node_code' => FlowDefinitionErrors::DESIGNATOR_INVALID,
            'min_assignees' => FlowDefinitionErrors::ASSIGNEES_RANGE,
            'max_assignees' => FlowDefinitionErrors::ASSIGNEES_RANGE,
            'prevents_applicant_approval' => self::PREVENTS_APPLICANT_APPROVAL_INVALID,
            'send_back_node_codes' => FlowDefinitionErrors::SEND_BACK_INVALID,
            'decision_rule' => FlowDefinitionErrors::DECISION_RULE_REQUIRED,
            'decision_value' => $decisionValue,
        ];
    }

    /**
     * 業務処理に渡す入力(規則が型を確かめた後に呼ぶ。欠けた欄は null)。
     */
    public function settings(): NodeSettingsInput
    {
        $codes = $this->input('send_back_node_codes');

        return new NodeSettingsInput(
            targetKind: $this->enum('target_kind', WorkflowTargetKind::class),
            targets: $this->input('targets') === null ? null : $this->subjectConditions('targets'),
            relativeBase: $this->enum('relative_base', WorkflowRelativeBase::class),
            relativeScope: $this->enum('relative_scope', WorkflowRelativeScope::class),
            companyPostId: $this->nullableInteger('company_post_id'),
            designatorNodeCode: $this->nullableString('designator_node_code'),
            minAssignees: $this->nullableInteger('min_assignees'),
            maxAssignees: $this->nullableInteger('max_assignees'),
            preventsApplicantApproval: $this->input('prevents_applicant_approval') === null
                ? null : $this->boolean('prevents_applicant_approval'),
            sendBackNodeCodes: is_array($codes) ? array_values(array_filter($codes, 'is_string')) : null,
            decisionRule: $this->enum('decision_rule', WorkflowDecisionRule::class),
            decisionValue: $this->nullableInteger('decision_value'),
        );
    }

    /**
     * @param  class-string  $class  列挙
     * @return list<string|Enum>
     */
    private static function enumRules(string $class): array
    {
        return ['bail', 'nullable', 'string', Rule::enum($class)];
    }

    private function nullableInteger(string $key): ?int
    {
        return $this->input($key) === null ? null : $this->integer($key);
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) ? $value : null;
    }

    /**
     * 差戻しできる先の並びの要素がどれもコードの文字列か。
     */
    private static function codesRule(): Closure
    {
        return function (string $attribute, mixed $codes, Closure $fail): void {
            if (is_array($codes) && array_filter($codes, fn (mixed $code) => ! is_string($code)) !== []) {
                $fail(FlowDefinitionErrors::SEND_BACK_INVALID);
            }
        };
    }
}
