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
 * ステップの設定の保存 workflow.admin.flows.update-node の入力(欄 = {@see NodeSettingsInput} の snake_case。docs の
 * 設計/ワークフロー/ルートの深さ/定義の管理.md 5.1 節、処理期限と確認する人 = 設計/ワークフロー/案件の運用/定義の管理.md 2 節)。
 * 入口は型だけを見て、送られた欄(null を含む)と送られなかった欄を分けて業務処理へ渡す。種類で決まる必須・任意・禁止(欠けた欄を含む)と
 * 値の規則は業務処理が 422 にする。型の誤りは、その欄の 6 節の文(選択の欄は「選択してください」の文)で返す。
 */
class FlowNodeSettingsRequest extends FlowDefinitionRequest
{
    use ReadsSubjectRows;

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
            'prevents_attachment_add' => ['bail', 'nullable', 'boolean'],
            'send_back_node_codes' => ['bail', 'nullable', 'list', self::codesRule()],
            'decision_rule' => self::enumRules(WorkflowDecisionRule::class),
            'decision_value' => $integer,
            'deadline_days' => $integer,
            'confirmers' => ['bail', 'nullable', 'list'],
            'confirmers.*' => ['bail', 'required', 'integer'],
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
            'prevents_applicant_approval' => FlowDefinitionErrors::PREVENTS_APPLICANT_APPROVAL_INVALID,
            'prevents_attachment_add' => FlowDefinitionErrors::PREVENTS_ATTACHMENT_ADD_INVALID,
            'send_back_node_codes' => FlowDefinitionErrors::SEND_BACK_INVALID,
            'decision_rule' => FlowDefinitionErrors::DECISION_RULE_REQUIRED,
            'decision_value' => $decisionValue,
            'deadline_days' => FlowDefinitionErrors::DEADLINE_RANGE,
            'confirmers' => FlowDefinitionErrors::CONFIRMER_MISSING,
            'confirmers.*.required' => FlowDefinitionErrors::CONFIRMER_MISSING,
            'confirmers.*.integer' => FlowDefinitionErrors::CONFIRMER_MISSING,
        ];
    }

    /**
     * 業務処理に渡す入力(規則が型を確かめた後に呼ぶ。欠けた欄は null で、そのキーを missing に並べる)。
     */
    public function settings(): NodeSettingsInput
    {
        $codes = $this->input('send_back_node_codes');
        $confirmers = $this->input('confirmers');
        $fields = array_filter(array_keys($this->rules()), fn (string $key) => ! str_contains($key, '*'));

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
            preventsAttachmentAdd: $this->input('prevents_attachment_add') === null
                ? null : $this->boolean('prevents_attachment_add'),
            deadlineDays: $this->nullableInteger('deadline_days'),
            confirmers: is_array($confirmers) ? array_values(array_map('intval', $confirmers)) : null,
            missing: array_values(array_filter($fields, fn (string $key) => ! $this->has($key))),
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
