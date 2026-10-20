<?php

namespace Database\Factories\Workflow;

use App\Enums\Authz\SubjectGroupPurpose;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\CompanyPost;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 位置は作る時点の版の最大 + 1。count() は全部の行の値を決めてから書くので、同じ版に複数を一度に作るときは位置を渡す(sequence など)。
 * 状態(起点で指定・処理の時に指定・配置・分岐開始)は位置 2 以上の承認以外のノードにし、指すノード(指定するノード・対の終了)を同じ版に先に作る。
 *
 * @extends Factory<WorkflowRouteNode>
 */
class WorkflowRouteNodeFactory extends Factory
{
    /**
     * Define the model's default state(版の最後に足すノード。位置 1 なら申請、ほかは承認。処理対象者の指定は対象者で指定 = 新しい用途 selection の対象者).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_flow_version_id' => WorkflowFlowVersion::factory(),
            'code' => fake()->unique()->bothify('node_????####'),
            'name' => fake()->words(2, true),
            'position' => fn (array $attributes): int => $this->maxPosition($attributes) + 1,
            'kind' => fn (array $attributes): WorkflowNodeKind => $attributes['position'] === 1 ? WorkflowNodeKind::Apply : WorkflowNodeKind::Approve,
            'target_kind' => WorkflowTargetKind::Fixed,
            'authz_subject_group_id' => AuthzSubjectGroup::factory()->state(['purpose' => SubjectGroupPurpose::Selection]),
            'relative_base' => null,
            'relative_scope' => null,
            'company_post_id' => null,
            'designator_workflow_route_node_id' => null,
            'min_assignees' => null,
            'max_assignees' => null,
            'decision_rule' => null,
            'decision_value' => null,
            'prevents_applicant_approval' => false,
            'closing_workflow_route_node_id' => null,
            'deadline_days' => null,
            'confirm_authz_subject_group_id' => null,
        ];
    }

    /**
     * 確認する人を持つ(新しい用途 selection の対象者。確認する人は申請・承認のノードだけ)。
     */
    public function confirming(): static
    {
        return $this->state(fn (): array => [
            'confirm_authz_subject_group_id' => AuthzSubjectGroup::factory()
                ->state(['purpose' => SubjectGroupPurpose::Selection]),
        ]);
    }

    /**
     * 起点で指定の承認のノード(申請者・所属組織・新しい役職)。
     */
    public function relative(): static
    {
        return $this->state(fn (): array => [
            'position' => fn (array $attributes): int => max(1, $this->maxPosition($attributes)) + 1,
            'kind' => WorkflowNodeKind::Approve,
            'target_kind' => WorkflowTargetKind::Relative,
            'authz_subject_group_id' => null,
            'relative_base' => WorkflowRelativeBase::Applicant,
            'relative_scope' => WorkflowRelativeScope::Own,
            'company_post_id' => CompanyPost::factory(),
        ]);
    }

    /**
     * 処理の時に指定のノード(既定は承認。指定するノード = 同じ版に先に作る承認のノード、1〜1 人)。
     */
    public function designated(WorkflowNodeKind $kind = WorkflowNodeKind::Approve): static
    {
        return $this->state(fn (): array => [
            'position' => fn (array $attributes): int => max(1, $this->maxPosition($attributes)) + 2,
            'kind' => $kind,
            'target_kind' => WorkflowTargetKind::Designated,
            'authz_subject_group_id' => null,
            'designator_workflow_route_node_id' => fn (array $attributes): int => $this->sibling($attributes, 1)->id,
            'min_assignees' => 1,
            'max_assignees' => 1,
            'decision_rule' => $kind === WorkflowNodeKind::Parallel ? WorkflowDecisionRule::All : null,
        ]);
    }

    /**
     * 分岐開始のノード(対の終了 = 同じ版に先に作る分岐終了のノード)。
     */
    public function branchStart(): static
    {
        return $this->state(fn (): array => [
            'position' => fn (array $attributes): int => max(1, $this->maxPosition($attributes)) + 2,
            'kind' => WorkflowNodeKind::BranchStart,
            'target_kind' => null,
            'authz_subject_group_id' => null,
            'closing_workflow_route_node_id' => fn (array $attributes): int => $this->sibling($attributes, 1, WorkflowNodeKind::BranchEnd)->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function maxPosition(array $attributes): int
    {
        return (int) WorkflowRouteNode::query()->where('workflow_flow_version_id', $attributes['workflow_flow_version_id'])->max('position');
    }

    /**
     * 同じ版の、位置 = 今の最大(1 未満なら 1)+ $offset のノード(承認なら対象者で指定、分岐・同期なら指定なし)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sibling(array $attributes, int $offset, WorkflowNodeKind $kind = WorkflowNodeKind::Approve): WorkflowRouteNode
    {
        $routing = in_array($kind, WorkflowNodeKind::routing(), true);

        return WorkflowRouteNode::factory()->createOne([
            'workflow_flow_version_id' => $attributes['workflow_flow_version_id'],
            'position' => max(1, $this->maxPosition($attributes)) + $offset,
            'kind' => $kind,
            ...($routing ? ['target_kind' => null, 'authz_subject_group_id' => null] : []),
        ]);
    }
}
