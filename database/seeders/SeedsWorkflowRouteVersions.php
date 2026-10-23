<?php

namespace Database\Seeders;

use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;

/**
 * {@see WorkflowSeeder} の版の形(docs の 設計/ワークフロー/ルートの深さ/初期データ.md 3 節): 一本道 A(段 2 のノードと同じ対象者)と分岐 B(版 3)。
 * 使う側は NODES(一本道のノード)と companyPostId() を持つ。
 */
trait SeedsWorkflowRouteVersions
{
    /** 期限の日数(設計/ワークフロー/案件の運用/初期データ.md 3 節。版 2・版 3 の同じコードのノードは同じ日数): ノードのコード => 日数 */
    private const DEADLINE_DAYS = ['section_chief' => 3, 'manager' => 5, 'council' => 3, 'owner' => 3];

    /**
     * フローの版 1 のノードが指す対象者(ノードのコード → 対象者の id。後の版の同じ役のノードが同じ対象者を指す = 設計/ワークフロー/ルートの深さ/初期データ.md 2 節)。
     *
     * @return array<string, int>
     */
    private function firstVersionSubjects(string $flowCode): array
    {
        $flow = WorkflowFlow::query()->where('code', $flowCode)->sole();
        $version = WorkflowFlowVersion::query()->where('workflow_flow_id', $flow->id)->where('number', 1)->sole();

        return WorkflowRouteNode::query()->where('workflow_flow_version_id', $version->id)->pluck('authz_subject_group_id', 'code')
            ->map(fn (mixed $id) => (int) $id)->all();
    }

    /**
     * 一本道 A(設計/ワークフロー/ルートの深さ/初期データ.md 3.1。段 2 のルートと同じノード・対象者、差戻しできる先 = 前の全部、申請者承認防止はオフ)。
     * 承認のノードに期限の日数と確認する人(設計/ワークフロー/案件の運用/初期データ.md 3 節)。
     *
     * @param  array<string, int>  $subjects  ノードのコード → 対象者の id
     * @param  int  $confirmer  確認する人の対象者の id
     * @return list<RouteNodeInput>
     */
    private function linearRoute(array $subjects, int $confirmer): array
    {
        return array_map(fn (int $index, array $node) => new RouteNodeInput(
            $node[0], $node[1], $node[2], WorkflowTargetKind::Fixed, subjectGroupId: $subjects[$node[0]],
            sendBackCodes: array_column(array_slice(self::NODES, 0, $index), 0),
            deadlineDays: self::DEADLINE_DAYS[$node[0]] ?? null, confirmSubjectGroupId: $node[2] === WorkflowNodeKind::Approve ? $confirmer : null,
        ), array_keys(self::NODES), self::NODES);
    }

    /**
     * 分岐 B(設計/ワークフロー/ルートの深さ/初期データ.md 3.2: 申請 → 課長 → 分岐「予算」(部長 / 何もしない)→ 関係部署の合議(縦配置)→ 責任者)。
     * 承認・合議のノードに期限の日数、課長と部長に確認する人(設計/ワークフロー/案件の運用/初期データ.md 3 節)。
     *
     * @param  int  $confirmer  確認する人の対象者の id
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function branchRoute(int $applicantSubjectId, int $confirmer): array
    {
        $front = ['apply', 'section_chief'];
        $days = self::DEADLINE_DAYS;

        return [[
            new RouteNodeInput('apply', '申請', WorkflowNodeKind::Apply, WorkflowTargetKind::Fixed, subjectGroupId: $applicantSubjectId),
            new RouteNodeInput('section_chief', '課長', WorkflowNodeKind::Approve, WorkflowTargetKind::Relative, relativeBase: WorkflowRelativeBase::Applicant,
                relativeScope: WorkflowRelativeScope::Own, companyPostId: $this->companyPostId('section-chief'), preventsApplicantApproval: true, sendBackCodes: ['apply'],
                deadlineDays: $days['section_chief'], confirmSubjectGroupId: $confirmer),
            new RouteNodeInput('budget', '予算', WorkflowNodeKind::BranchStart, closingCode: 'budget_end'),
            new RouteNodeInput('manager', '部長', WorkflowNodeKind::Approve, WorkflowTargetKind::Relative, relativeBase: WorkflowRelativeBase::Applicant,
                relativeScope: WorkflowRelativeScope::Parent, companyPostId: $this->companyPostId('manager'), preventsApplicantApproval: true, sendBackCodes: $front,
                deadlineDays: $days['manager'], confirmSubjectGroupId: $confirmer),
            new RouteNodeInput('budget_end', '分岐終了', WorkflowNodeKind::BranchEnd),
            new RouteNodeInput('council', '関係部署の合議', WorkflowNodeKind::Parallel, WorkflowTargetKind::Designated, designatorCode: 'section_chief', minAssignees: 2,
                maxAssignees: 5, decisionRule: WorkflowDecisionRule::Count, decisionValue: 2, preventsApplicantApproval: true, sendBackCodes: $front,
                deadlineDays: $days['council']),
            new RouteNodeInput('owner', '責任者', WorkflowNodeKind::Approve, WorkflowTargetKind::Designated, designatorCode: 'section_chief', minAssignees: 1,
                maxAssignees: 1, preventsApplicantApproval: true, sendBackCodes: $front, deadlineDays: $days['owner']),
        ], [
            new RouteEdgeInput('apply', 'section_chief'),
            new RouteEdgeInput('section_chief', 'budget'),
            new RouteEdgeInput('budget', 'manager', 1, '予算 300 万円以上', ['all' => [['field' => 'budget', 'op' => 'ge', 'value' => 300]]]),
            new RouteEdgeInput('budget', 'budget_end', 2, '何もしない', ['all' => [['field' => 'budget', 'op' => 'lt', 'value' => 300]]]),
            new RouteEdgeInput('manager', 'budget_end'),
            new RouteEdgeInput('budget_end', 'council'),
            new RouteEdgeInput('council', 'owner'),
        ]];
    }
}
