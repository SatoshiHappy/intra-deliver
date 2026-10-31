<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\CompanyPost;

/**
 * ステップの設定の入力を、欄の扱いと値で確かめる(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 5.1 節・6 節)。
 * DB の検査制約が止める組を書く前に 422 にするため(データモデル/README.md の 1 節の例外)。
 */
class NodeSettingsValidator
{
    private const REQUIRED = 'required';

    private const OPTIONAL = 'optional';

    private const FORBIDDEN = 'forbidden';

    /** 指定の種類で扱いが決まる欄 */
    private const BY_TARGET_KIND = [
        'targets', 'relative_base', 'relative_scope', 'company_post_id',
        'designator_node_code', 'min_assignees', 'max_assignees',
    ];

    /**
     * @param  array<string, int>  $nodeIds  同じ版のノードのコード → id
     * @return array<string, string> 入力のキー → 文言(キーごとに 1 つ)
     */
    public function errors(WorkflowNodeKind $kind, NodeSettingsInput $input, array $nodeIds): array
    {
        $errors = [];

        foreach ($this->usages($kind, $input) as $field => $usage) {
            $error = self::isSkipped($field, $errors)
                ? null : $this->fieldError($field, $usage, $kind, $input, $nodeIds);

            if ($error !== null) {
                $errors[$field] = $error;
            }
        }

        return $this->withCountMinimum($errors, $kind, $input);
    }

    /**
     * 前の欄の誤りで確かめない欄か。
     *
     * @param  array<string, string>  $errors  ここまでの誤り
     */
    private static function isSkipped(string $field, array $errors): bool
    {
        return match ($field) {
            'decision_value' => isset($errors['decision_rule']),
            default => in_array($field, self::BY_TARGET_KIND, true) && isset($errors['target_kind']),
        };
    }

    /**
     * 欄 → 扱い。欄を足すときは、ここに 1 行と値の検証を足すだけで済むように 1 か所に置く。
     *
     * @return array<string, string>
     */
    private function usages(WorkflowNodeKind $kind, NodeSettingsInput $input): array
    {
        $only = fn (bool $applies, string $usage) => $applies ? $usage : self::FORBIDDEN;
        $targetKind = $input->targetKind;
        $isParallel = $kind === WorkflowNodeKind::Parallel;
        $approving = $kind->isIn(WorkflowNodeKind::approving());
        $valueUsage = match (true) {
            ! $isParallel => self::FORBIDDEN,
            $input->decisionRule === WorkflowDecisionRule::Ratio => self::REQUIRED,
            $input->decisionRule !== WorkflowDecisionRule::Count => self::FORBIDDEN,
            default => self::countMaximum($kind, $input) === null ? self::OPTIONAL : self::REQUIRED,
        };

        return [
            'target_kind' => $only(! $kind->isIn(WorkflowNodeKind::routing()), self::REQUIRED),
            'targets' => $only($targetKind === WorkflowTargetKind::Fixed, self::REQUIRED),
            'relative_base' => $only($targetKind === WorkflowTargetKind::Relative, self::REQUIRED),
            'relative_scope' => $only($targetKind === WorkflowTargetKind::Relative, self::REQUIRED),
            'company_post_id' => $only($targetKind === WorkflowTargetKind::Relative, self::OPTIONAL),
            'designator_node_code' => $only($targetKind === WorkflowTargetKind::Designated, self::REQUIRED),
            'min_assignees' => $only($targetKind === WorkflowTargetKind::Designated, self::REQUIRED),
            'max_assignees' => $only($targetKind === WorkflowTargetKind::Designated, self::REQUIRED),
            'prevents_applicant_approval' => $only($approving, self::OPTIONAL),
            'send_back_node_codes' => $only($approving, self::OPTIONAL),
            'decision_rule' => $only($isParallel, self::REQUIRED),
            'decision_value' => $valueUsage,
        ];
    }

    /**
     * @param  array<string, int>  $nodeIds
     */
    private function fieldError(
        string $field,
        string $usage,
        WorkflowNodeKind $kind,
        NodeSettingsInput $input,
        array $nodeIds,
    ): ?string {
        $isFilled = self::isFilled(self::valueOf($input, $field));

        return match (true) {
            $usage === self::FORBIDDEN => $isFilled ? FlowDefinitionErrors::NOT_ALLOWED : null,
            ! $isFilled => $usage === self::REQUIRED ? $this->requiredError($field, $input) : null,
            default => $this->valueError($field, $kind, $input, $nodeIds),
        };
    }

    /**
     * 必須の欄が空のときの文言。
     */
    private function requiredError(string $field, NodeSettingsInput $input): string
    {
        return match ($field) {
            'target_kind' => FlowDefinitionErrors::TARGET_KIND_REQUIRED,
            'targets' => FlowDefinitionErrors::TARGETS_REQUIRED,
            'relative_base' => FlowDefinitionErrors::RELATIVE_BASE_REQUIRED,
            'relative_scope' => FlowDefinitionErrors::RELATIVE_SCOPE_REQUIRED,
            'designator_node_code' => FlowDefinitionErrors::DESIGNATOR_INVALID,
            'decision_rule' => FlowDefinitionErrors::DECISION_RULE_REQUIRED,
            'decision_value' => $input->decisionRule === WorkflowDecisionRule::Ratio
                ? FlowDefinitionErrors::DECISION_RATIO_RANGE : FlowDefinitionErrors::DECISION_COUNT_RANGE,
            default => FlowDefinitionErrors::ASSIGNEES_RANGE,
        };
    }

    /**
     * 空でない欄の値の誤り(対象者の行の相手は、対象者の口が確かめるのでここで見ない)。
     *
     * @param  array<string, int>  $nodeIds
     */
    private function valueError(
        string $field,
        WorkflowNodeKind $kind,
        NodeSettingsInput $input,
        array $nodeIds,
    ): ?string {
        $isValid = match ($field) {
            'target_kind' => self::isTargetKindValid($kind, $input->targetKind),
            'company_post_id' => CompanyPost::query()->whereKey($input->companyPostId)->exists(),
            'designator_node_code' => isset($nodeIds[(string) $input->designatorNodeCode]),
            'min_assignees' => self::isMinValid($input),
            'max_assignees' => self::inRange($input->maxAssignees, 1, 20),
            'send_back_node_codes' => array_diff($input->sendBackNodeCodes ?? [], array_keys($nodeIds)) === [],
            'decision_value' => self::isDecisionValueValid($kind, $input),
            default => true,
        };

        return $isValid ? null : match ($field) {
            'target_kind' => FlowDefinitionErrors::NOT_ALLOWED,
            'company_post_id' => FlowDefinitionErrors::COMPANY_POST_MISSING,
            'designator_node_code' => FlowDefinitionErrors::DESIGNATOR_INVALID,
            'send_back_node_codes' => FlowDefinitionErrors::SEND_BACK_INVALID,
            default => $this->requiredError($field, $input),
        };
    }

    /**
     * 必要な人数の上限(必要な人数を確かめないときは null)。
     */
    private static function countMaximum(WorkflowNodeKind $kind, NodeSettingsInput $input): ?int
    {
        $isJudged = self::isTargetKindValid($kind, $input->targetKind) && self::isMinValid($input);

        return $isJudged && $input->minAssignees >= 1 ? $input->minAssignees : null;
    }

    private static function isDecisionValueValid(WorkflowNodeKind $kind, NodeSettingsInput $input): bool
    {
        if ($input->decisionRule === WorkflowDecisionRule::Ratio) {
            return self::inRange($input->decisionValue, 1, 100);
        }

        $maximum = self::countMaximum($kind, $input);

        return $maximum === null || self::inRange($input->decisionValue, 1, $maximum);
    }

    private static function isTargetKindValid(WorkflowNodeKind $kind, ?WorkflowTargetKind $targetKind): bool
    {
        return $kind->isIn(WorkflowNodeKind::routing())
            ? $targetKind === null
            : in_array($targetKind, self::targetKindsOf($kind), true);
    }

    /**
     * 人数の可決の条件の、最小 0 の誤りを重ねる。
     *
     * @param  array<string, string>  $errors
     * @return array<string, string>
     */
    private function withCountMinimum(array $errors, WorkflowNodeKind $kind, NodeSettingsInput $input): array
    {
        $isCount = $kind === WorkflowNodeKind::Parallel && $input->decisionRule === WorkflowDecisionRule::Count;
        $judged = ! isset($errors['target_kind']) && ! isset($errors['decision_rule'])
            && ! isset($errors['min_assignees']);

        if ($isCount && $judged && $input->minAssignees === 0) {
            $errors['min_assignees'] = FlowDefinitionErrors::COUNT_MIN_ZERO;
        }

        return $errors;
    }

    /**
     * @return list<WorkflowTargetKind>
     */
    private static function targetKindsOf(WorkflowNodeKind $kind): array
    {
        return match (true) {
            $kind === WorkflowNodeKind::Apply => [WorkflowTargetKind::Fixed],
            $kind->isIn(WorkflowNodeKind::expanding()) => [WorkflowTargetKind::Designated],
            default => WorkflowTargetKind::cases(),
        };
    }

    private static function valueOf(NodeSettingsInput $input, string $field): mixed
    {
        return match ($field) {
            'target_kind' => $input->targetKind,
            'targets' => $input->targets,
            'relative_base' => $input->relativeBase,
            'relative_scope' => $input->relativeScope,
            'company_post_id' => $input->companyPostId,
            'designator_node_code' => $input->designatorNodeCode,
            'min_assignees' => $input->minAssignees,
            'max_assignees' => $input->maxAssignees,
            'prevents_applicant_approval' => $input->preventsApplicantApproval,
            'send_back_node_codes' => $input->sendBackNodeCodes,
            'decision_rule' => $input->decisionRule,
            default => $input->decisionValue,
        };
    }

    /**
     * 空の意味(真偽の欄は false、集まりの欄は空の配列も空)。
     */
    private static function isFilled(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== [];
    }

    /**
     * 最小の範囲(最大が範囲の中なら最大以下も)。
     */
    private static function isMinValid(NodeSettingsInput $input): bool
    {
        return self::inRange($input->minAssignees, 0, 20)
            && ! (self::inRange($input->maxAssignees, 1, 20) && $input->minAssignees > $input->maxAssignees);
    }

    private static function inRange(?int $value, int $min, int $max): bool
    {
        return $value !== null && $value >= $min && $value <= $max;
    }
}
