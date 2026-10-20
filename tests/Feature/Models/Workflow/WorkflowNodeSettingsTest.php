<?php

namespace Tests\Feature\Models\Workflow;

use App\Enums\Authz\SubjectGroupPurpose;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\CompanyPost;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowRouteNode;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * ノードの設定の制約(docs の 設計/ワークフロー/ルートの深さ/データモデル/定義の表.md 2.3、案件の表.md 3.2、直列化と検査.md 7 節の「ルートのノード」):
 * 種類と処理対象者の指定の種類の組、指定の種類と列の組、人数、可決の条件、申請者承認防止は承認する種類だけ、分岐開始・同期開始は対の終了を持つ、指すノードは同じ版
 * (案件のノードは同じ案件)。ルートのノードと、それを写した案件のノードに同じ式を置くので、両方の表で同じ行を確かめる。行ごとに 1 つの制約だけに反する値にする。
 */
final class WorkflowNodeSettingsTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /** ノード(ルートのノードと、それを写した案件のノード)のモデル */
    private const NODE_MODELS = [WorkflowRouteNode::class, WorkflowCaseNode::class];

    /**
     * 拒む行: 名前 → [factory の状態(null = 位置 2 の承認・対象者で指定), 状態の種類, 上書きする列({node} = route か case), 拒む制約(表の名前の後ろ)]。
     *
     * @return array<string, array{string|null, WorkflowNodeKind|null, array<string, mixed>, string}>
     */
    private static function rejectedRows(): array
    {
        $approve = ['position' => 2];
        $routing = ['position' => 2, 'target_kind' => null, 'authz_subject_group_id' => null];

        return [
            '分岐のノードが処理対象者の指定の種類を持つ' => [null, null, [...$approve, 'kind' => WorkflowNodeKind::BranchEnd], 'KIND_TARGET_KIND'],
            '承認のノードが処理対象者の指定の種類を持たない' => [null, null, [...$approve, 'target_kind' => null, 'authz_subject_group_id' => null], 'KIND_TARGET_KIND'],
            '申請のノードの起点で指定' => ['relative', null, ['position' => 1, 'kind' => WorkflowNodeKind::Apply], 'KIND_TARGET_KIND'],
            '申請のノードの処理の時に指定' => ['designated', null, ['position' => 1, 'kind' => WorkflowNodeKind::Apply], 'KIND_TARGET_KIND'],
            '横配置の対象者で指定' => [null, null, [...$approve, 'kind' => WorkflowNodeKind::Serial], 'KIND_TARGET_KIND'],
            '対象者で指定で対象者が無い' => [null, null, [...$approve, 'authz_subject_group_id' => null], 'target_kind_authz_subject_group_id_check'],
            '起点で指定で起点が無い' => ['relative', null, ['relative_base' => null], 'target_kind_relative_base_relative_scope_company_post_id_check'],
            '起点で指定で組織の範囲が無い' => ['relative', null, ['relative_scope' => null], 'target_kind_relative_base_relative_scope_company_post_id_check'],
            '対象者で指定が役職を持つ' => [null, null, [...$approve, 'company_post_id' => fn (): int => CompanyPost::factory()->createOne()->id], 'target_kind_relative_base_relative_scope_company_post_id_check'],
            '処理の時に指定で人数の最小が無い' => ['designated', null, ['min_assignees' => null], 'target_kind_designator_workflow_{node}_node_id_min_assignees_max_assignees_check'],
            '処理の時に指定で人数の最大が無い' => ['designated', null, ['max_assignees' => null], 'target_kind_designator_workflow_{node}_node_id_min_assignees_max_assignees_check'],
            '対象者で指定が人数を持つ' => [null, null, [...$approve, 'min_assignees' => 1, 'max_assignees' => 1], 'target_kind_designator_workflow_{node}_node_id_min_assignees_max_assignees_check'],
            '最小 > 最大' => ['designated', null, ['min_assignees' => 3, 'max_assignees' => 2], 'min_assignees_max_assignees_check'],
            '最大 21' => ['designated', null, ['max_assignees' => 21], 'min_assignees_max_assignees_check'],
            '最小 -1' => ['designated', null, ['min_assignees' => -1], 'min_assignees_max_assignees_check'],
            '縦配置で可決の条件が無い' => ['designated', WorkflowNodeKind::Parallel, ['decision_rule' => null], 'kind_decision_rule_check'],
            '承認のノードが可決の条件を持つ' => [null, null, [...$approve, 'decision_rule' => WorkflowDecisionRule::All], 'kind_decision_rule_check'],
            '全員が値を持つ' => ['designated', WorkflowNodeKind::Parallel, ['decision_value' => 1], 'decision_rule_decision_value_check'],
            '人数が値を持たない' => ['designated', WorkflowNodeKind::Parallel, ['decision_rule' => WorkflowDecisionRule::Count], 'decision_rule_decision_value_check'],
            '縦配置でないノードが値だけを持つ' => [null, null, [...$approve, 'decision_value' => 1], 'decision_rule_decision_value_check'],
            '人数の条件の値が最小より大きい' => ['designated', WorkflowNodeKind::Parallel, ['min_assignees' => 2, 'max_assignees' => 5, 'decision_rule' => WorkflowDecisionRule::Count, 'decision_value' => 3], 'decision_rule_decision_value_min_assignees_check'],
            '割合 0' => ['designated', WorkflowNodeKind::Parallel, ['decision_rule' => WorkflowDecisionRule::Ratio, 'decision_value' => 0], 'decision_rule_decision_value_check'],
            '割合 101' => ['designated', WorkflowNodeKind::Parallel, ['decision_rule' => WorkflowDecisionRule::Ratio, 'decision_value' => 101], 'decision_rule_decision_value_check'],
            '分岐のノードの申請者承認防止' => [null, null, [...$routing, 'kind' => WorkflowNodeKind::BranchEnd, 'prevents_applicant_approval' => true], 'prevents_applicant_approval_kind_check'],
            '申請のノードの申請者承認防止' => [null, null, ['position' => 1, 'kind' => WorkflowNodeKind::Apply, 'prevents_applicant_approval' => true], 'prevents_applicant_approval_kind_check'],
            '分岐開始の対の終了が無い' => [null, null, [...$routing, 'kind' => WorkflowNodeKind::BranchStart], 'kind_closing_workflow_{node}_node_id_check'],
            '承認のノードが対の終了を持つ' => ['branchStart', null, ['kind' => WorkflowNodeKind::Approve, 'target_kind' => WorkflowTargetKind::Fixed, 'authz_subject_group_id' => fn (): int => AuthzSubjectGroup::factory()->createOne(['purpose' => SubjectGroupPurpose::Selection])->id], 'kind_closing_workflow_{node}_node_id_check'],
        ];
    }

    /**
     * 受ける行: 名前 → [factory の状態, 状態の種類, 上書きする列]。
     *
     * @return array<string, array{string, WorkflowNodeKind|null, array<string, mixed>}>
     */
    private static function acceptedRows(): array
    {
        return [
            '起点で指定' => ['relative', null, []],
            '起点で指定で役職なし' => ['relative', null, ['company_post_id' => null]],
            '処理の時に指定(最小 0)' => ['designated', null, ['min_assignees' => 0, 'max_assignees' => 20, 'prevents_applicant_approval' => true]],
            '横配置' => ['designated', WorkflowNodeKind::Serial, ['max_assignees' => 5]],
            '縦配置(全員)' => ['designated', WorkflowNodeKind::Parallel, ['max_assignees' => 3, 'prevents_applicant_approval' => true]],
            '縦配置(人数 = 最小)' => ['designated', WorkflowNodeKind::Parallel, ['min_assignees' => 2, 'max_assignees' => 5, 'decision_rule' => WorkflowDecisionRule::Count, 'decision_value' => 2]],
            '縦配置(割合 100)' => ['designated', WorkflowNodeKind::Parallel, ['decision_rule' => WorkflowDecisionRule::Ratio, 'decision_value' => 100]],
            '分岐開始' => ['branchStart', null, []],
        ];
    }

    /**
     * @return array<string, array{class-string<WorkflowRouteNode|WorkflowCaseNode>, string|null, WorkflowNodeKind|null, array<string, mixed>, string}>
     */
    public static function rejected(): array
    {
        $cases = [];

        foreach (self::NODE_MODELS as $model) {
            foreach (self::rejectedRows() as $label => $row) {
                $cases[class_basename($model).' '.$label] = [$model, ...$row];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{class-string<WorkflowRouteNode|WorkflowCaseNode>, string, WorkflowNodeKind|null, array<string, mixed>}>
     */
    public static function accepted(): array
    {
        $cases = [];

        foreach (self::NODE_MODELS as $model) {
            foreach (self::acceptedRows() as $label => $row) {
                $cases[class_basename($model).' '.$label] = [$model, ...$row];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{class-string<WorkflowRouteNode|WorkflowCaseNode>, string, string}>
     */
    public static function referencesOutsideTheScope(): array
    {
        $cases = [];

        foreach (self::NODE_MODELS as $model) {
            foreach (['designated' => 'designator', 'branchStart' => 'closing'] as $state => $column) {
                $cases[class_basename($model).' '.$column] = [$model, $state, $column];
            }
        }

        return $cases;
    }

    /**
     * @param  class-string<WorkflowRouteNode|WorkflowCaseNode>  $model
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('rejected')]
    public function test_rejects_a_node_whose_settings_do_not_match(string $model, ?string $state, ?WorkflowNodeKind $kind, array $overrides, string $constraint): void
    {
        $table = (new $model)->getTable();
        $node = $model === WorkflowRouteNode::class ? 'route' : 'case';
        $suffix = $constraint === 'KIND_TARGET_KIND'
            ? ($node === 'route' ? 'kind_target_kind_check' : 'parent_workflow_case_node_id_kind_target_kind_check')
            : str_replace('{node}', $node, $constraint);

        $this->assertRejectedBy("{$table}_{$suffix}", fn () => $this->factory($model, $state, $kind)->createOne($this->evaluated($overrides)));
    }

    /**
     * @param  class-string<WorkflowRouteNode|WorkflowCaseNode>  $model
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('accepted')]
    public function test_accepts_a_node_whose_settings_match(string $model, string $state, ?WorkflowNodeKind $kind, array $overrides): void
    {
        $row = $this->factory($model, $state, $kind)->createOne($overrides);

        $this->assertModelExists($row);
    }

    /**
     * 指定するノード・対の終了は同じ版(案件のノードは同じ案件)のノードだけを指せる。
     *
     * @param  class-string<WorkflowRouteNode|WorkflowCaseNode>  $model
     */
    #[DataProvider('referencesOutsideTheScope')]
    public function test_rejects_pointing_to_a_node_outside_the_scope(string $model, string $state, string $column): void
    {
        $row = $this->factory($model, $state, null)->createOne();
        $scope = $row instanceof WorkflowRouteNode ? 'workflow_flow_version_id' : 'workflow_case_id';
        $node = $row instanceof WorkflowRouteNode ? 'route' : 'case';
        $elsewhere = Factory::factoryForModel($model)->createOne(['position' => 2]);

        $this->assertRejectedBy(
            "{$row->getTable()}_{$scope}_{$column}_workflow_{$node}_node_id_foreign",
            fn () => $row->update(["{$column}_workflow_{$node}_node_id" => $elsewhere->id]),
        );
    }

    /**
     * @param  class-string<Model>  $model
     * @return Factory<Model>
     */
    private function factory(string $model, ?string $state, ?WorkflowNodeKind $kind): Factory
    {
        $factory = Factory::factoryForModel($model);

        return match (true) {
            $state === null => $factory,
            $kind !== null => $factory->{$state}($kind),
            default => $factory->{$state}(),
        };
    }

    /**
     * 上書きする列の値(Closure は書く直前に評価する)。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function evaluated(array $overrides): array
    {
        return array_map(fn (mixed $value): mixed => $value instanceof Closure ? $value() : $value, $overrides);
    }
}
