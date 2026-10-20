<?php

namespace Database\Factories\Workflow;

use App\Enums\Authz\SubjectGroupPurpose;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 到達日時は状態によらず持つ(現在のノードにする書き換えが到達日時の検査制約に掛からないように。マイグレーションで埋めた行と同じ形)。
 * 位置は作る時点の案件の最大 + 1。count() は全部の行の値を決めてから書くので、同じ案件に複数を一度に作るときは位置を渡す(sequence など)。
 * 状態(起点で指定・処理の時に指定・配置・分岐開始・展開の行)は、指すノード(指定するノード・対の終了・親)を同じ案件に先に作る。
 *
 * @extends Factory<WorkflowCaseNode>
 */
class WorkflowCaseNodeFactory extends Factory
{
    /**
     * Define the model's default state(未完了の案件の最後に足す、到達していないノード。位置 1 なら申請、ほかは承認。
     * 処理対象者の指定は対象者で指定 = 新しい用途 selection の対象者).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_case_id' => WorkflowCase::factory()->active(),
            'position' => fn (array $attributes): int => $this->maxPosition($attributes) + 1,
            'code' => fake()->unique()->bothify('node_????####'),
            'name' => fake()->words(2, true),
            'kind' => fn (array $attributes): WorkflowNodeKind => $attributes['position'] === 1 ? WorkflowNodeKind::Apply : WorkflowNodeKind::Approve,
            'target_kind' => WorkflowTargetKind::Fixed,
            'authz_subject_group_id' => AuthzSubjectGroup::factory()->state(['purpose' => SubjectGroupPurpose::Selection]),
            'relative_base' => null,
            'relative_scope' => null,
            'company_post_id' => null,
            'designator_workflow_case_node_id' => null,
            'min_assignees' => null,
            'max_assignees' => null,
            'decision_rule' => null,
            'decision_value' => null,
            'prevents_applicant_approval' => false,
            'closing_workflow_case_node_id' => null,
            'parent_workflow_case_node_id' => null,
            'sequence' => null,
            'state' => WorkflowNodeState::Unreached,
            'workflow_case_history_id' => null,
            'reached_at' => now(),
            'previous_user_id' => null,
            'previous_department_id' => null,
            'deadline_days' => null,
            'confirm_authz_subject_group_id' => null,
        ];
    }

    /**
     * 確認する人を持つ(新しい用途 selection の対象者。確認する人は申請・承認のノードだけで、展開の行は持たない)。
     */
    public function confirming(): static
    {
        return $this->state(fn (): array => [
            'confirm_authz_subject_group_id' => AuthzSubjectGroup::factory()
                ->state(['purpose' => SubjectGroupPurpose::Selection]),
        ]);
    }

    /**
     * 到達の前処理者と起点の組織を持つ(新しいユーザーと組織)。
     */
    public function withPrevious(): static
    {
        return $this->state(fn (): array => [
            'previous_user_id' => User::factory(),
            'previous_department_id' => Department::factory(),
        ]);
    }

    /**
     * 現在のノードにする(申請のノードは再申請待ち、承認のノードは承認待ち)。
     */
    public function current(): static
    {
        return $this->state(fn (): array => [
            'state' => fn (array $attributes): WorkflowNodeState => $attributes['kind'] === WorkflowNodeKind::Apply ? WorkflowNodeState::Reapply : WorkflowNodeState::Waiting,
        ]);
    }

    /**
     * 処理済みにする。処理済みのノードは自分を処理した履歴の行を指すので、到達していないノードを作った後に
     * 履歴の行を作り、ノードをその行を指す処理済みに書き換える(make では到達していないノードのまま)。
     */
    public function done(): static
    {
        return $this->afterCreating(function (WorkflowCaseNode $node): void {
            $history = WorkflowCaseHistory::factory()->createOne(['workflow_case_node_id' => $node->id]);

            $node->update(['state' => WorkflowNodeState::Done, 'workflow_case_history_id' => $history->id]);
        });
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
     * 処理の時に指定のノード(既定は承認。横配置・縦配置も。指定するノード = 同じ案件に先に作る承認のノード、1〜1 人)。
     */
    public function designated(WorkflowNodeKind $kind = WorkflowNodeKind::Approve): static
    {
        return $this->state(fn (): array => [
            'position' => fn (array $attributes): int => max(1, $this->maxPosition($attributes)) + 2,
            'kind' => $kind,
            'target_kind' => WorkflowTargetKind::Designated,
            'authz_subject_group_id' => null,
            'designator_workflow_case_node_id' => fn (array $attributes): int => $this->sibling($attributes, 1)->id,
            'min_assignees' => 1,
            'max_assignees' => 1,
            'decision_rule' => $kind === WorkflowNodeKind::Parallel ? WorkflowDecisionRule::All : null,
        ]);
    }

    /**
     * 分岐開始のノード(対の終了 = 同じ案件に先に作る分岐終了のノード)。
     */
    public function branchStart(): static
    {
        return $this->state(fn (): array => [
            'position' => fn (array $attributes): int => max(1, $this->maxPosition($attributes)) + 2,
            'kind' => WorkflowNodeKind::BranchStart,
            'target_kind' => null,
            'authz_subject_group_id' => null,
            'closing_workflow_case_node_id' => fn (array $attributes): int => $this->sibling($attributes, 1, WorkflowNodeKind::BranchEnd)->id,
        ]);
    }

    /**
     * 展開の行(親 = 同じ案件に先に作る横配置のノード、番号 1。位置・コード・写しの列を持たない)。
     */
    public function child(): static
    {
        return $this->state(fn (): array => [
            'position' => null,
            'code' => null,
            'name' => '承認 1',
            'kind' => WorkflowNodeKind::Approve,
            'target_kind' => null,
            'authz_subject_group_id' => null,
            'parent_workflow_case_node_id' => fn (array $attributes): int => WorkflowCaseNode::factory()->designated(WorkflowNodeKind::Serial)->createOne(['workflow_case_id' => $attributes['workflow_case_id']])->id,
            'sequence' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function maxPosition(array $attributes): int
    {
        return (int) WorkflowCaseNode::query()->where('workflow_case_id', $attributes['workflow_case_id'])->max('position');
    }

    /**
     * 同じ案件の、位置 = 今の最大(1 未満なら 1)+ $offset のノード(承認なら対象者で指定、分岐・同期なら指定なし)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sibling(array $attributes, int $offset, WorkflowNodeKind $kind = WorkflowNodeKind::Approve): WorkflowCaseNode
    {
        $routing = in_array($kind, WorkflowNodeKind::routing(), true);

        return WorkflowCaseNode::factory()->createOne([
            'workflow_case_id' => $attributes['workflow_case_id'],
            'position' => max(1, $this->maxPosition($attributes)) + $offset,
            'kind' => $kind,
            ...($routing ? ['target_kind' => null, 'authz_subject_group_id' => null] : []),
        ]);
    }
}
