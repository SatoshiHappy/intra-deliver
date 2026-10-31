<?php

namespace Tests\Support;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\NodeSettingsInput;
use App\Actions\Workflow\SaveNodeSettings;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use App\Queries\Authz\Api\SelectionSubjects;
use App\Support\AccessContext;

/**
 * ステップの設定の保存のテストの手助け(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 5.1・8 節)。入力は版のノードの今の設定から作り、
 * 変える欄だけを上書きする(欄の名前は {@see NodeSettingsInput} の引数の名前)。版はテストのフロー({@see RunsWorkflowOperations::FLOW_CODE})のもの。
 */
trait SavesNodeSettings
{
    use BuildsRouteVersions;

    /**
     * @param  array<string, mixed>  $overrides  欄の名前 → 値
     */
    protected function settingsOf(int $versionId, string $nodeCode, array $overrides = []): NodeSettingsInput
    {
        $node = $this->routeNode($versionId, $nodeCode);
        $group = $node->authz_subject_group_id;
        $codes = WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->pluck('code', 'id')->all();
        $sendBacks = WorkflowRouteSendBack::query()->where('workflow_route_node_id', $node->id)->orderBy('id')
            ->pluck('target_workflow_route_node_id')->all();
        $current = [
            'targetKind' => $node->target_kind,
            'targets' => $group === null ? null : $this->targetsOf((int) $group),
            'relativeBase' => $node->relative_base, 'relativeScope' => $node->relative_scope,
            'companyPostId' => $node->company_post_id,
            'designatorNodeCode' => $codes[$node->designator_workflow_route_node_id] ?? null,
            'minAssignees' => $node->min_assignees, 'maxAssignees' => $node->max_assignees,
            'preventsApplicantApproval' => $node->prevents_applicant_approval,
            'sendBackNodeCodes' => array_values(array_map(fn (mixed $id) => (string) $codes[$id], $sendBacks)),
            'decisionRule' => $node->decision_rule, 'decisionValue' => $node->decision_value,
        ];

        return new NodeSettingsInput(...[...$current, ...$overrides]);
    }

    /**
     * 版のノードを、今の設定に $overrides を重ねて保存する。
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function saveSettings(int $versionId, string $nodeCode, array $overrides = []): void
    {
        $number = WorkflowFlowVersion::query()->findOrFail($versionId)->number;
        $input = $this->settingsOf($versionId, $nodeCode, $overrides);

        app(SaveNodeSettings::class)->execute(self::FLOW_CODE, $number, $nodeCode, $input);
    }

    protected function routeNode(int $versionId, string $code): WorkflowRouteNode
    {
        return WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->where('code', $code)->sole();
    }

    /**
     * 失敗で何も変わらないことを比べる写し(ノードの列・差戻しできる先の行・対象者の数)。
     *
     * @return array<string, mixed>
     */
    protected function settingsSnapshot(): array
    {
        return [
            'nodes' => WorkflowRouteNode::query()->orderBy('id')->get()
                ->map(fn (WorkflowRouteNode $node) => $node->getAttributes())->all(),
            'send_backs' => WorkflowRouteSendBack::query()->orderBy('id')->get()
                ->map(fn (WorkflowRouteSendBack $row) => $row->getAttributes())->all(),
            'subject_groups' => AuthzSubjectGroup::query()->count(),
        ];
    }

    /**
     * 対象者の条件を入力の行に戻したもの(作った順)。
     *
     * @return list<SelectionCondition>
     */
    protected function targetsOf(int $subjectGroupId): array
    {
        $groups = app(SelectionSubjects::class)
            ->conditionsOf([$subjectGroupId], $this->operationDay(), AccessContext::LOCALE_CODE);

        return array_map(
            fn (array $condition) => new SelectionCondition($condition['kind'], $condition['id']),
            $groups[$subjectGroupId]['conditions'],
        );
    }
}
