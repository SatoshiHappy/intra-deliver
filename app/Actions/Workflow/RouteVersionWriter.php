<?php

namespace App\Actions\Workflow;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use Illuminate\Validation\ValidationException;

/**
 * 版のルート(ノード・辺・差戻しできる先)を書く 1 か所(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 1 節の「形つきの版」・4 節の複製)。
 * ノードは参照される側(対の終了・指定するノード)を先に書き({@see ReferenceFirstOrder})、書いた後に同じトランザクションの中で定義の検査
 * ({@see RouteDefinitionValidator})を通す。初期データ・複製の違反は {@see RouteDefinitionViolated}(LogicException。呼び手の誤り = 定義の管理.md 3 節)。呼び手のトランザクションの中で書く。
 */
class RouteVersionWriter
{
    public function __construct(
        private RouteVersionInputRules $rules,
        private CreateSelectionSubjectGroup $createSelection,
        private ReferenceFirstOrder $order,
        private RouteDefinitionValidator $validator,
    ) {}

    /**
     * 入力を確かめて版に書く。
     *
     * @param  int  $recordModuleId  フローのコンテンツの業務モジュール(分岐の条件を照らす)
     * @param  list<RouteNodeInput>  $nodes  位置の順(1 番目 = 申請)
     * @param  list<RouteEdgeInput>  $edges
     *
     * @throws ValidationException 入力の誤り({@see RouteVersionInputRules::check()})、処理対象者の誤り(対象者の口のキーのまま)
     * @throws RouteDefinitionViolated 定義の検査に外れたとき
     */
    public function write(int $versionId, int $recordModuleId, array $nodes, array $edges): void
    {
        $conditions = $this->rules->check($nodes, $edges, $recordModuleId);
        $this->store($versionId, $nodes, $edges, $conditions);
    }

    /**
     * ほかの版のルートと同じ中身を版に書く(複製。ノードの id は新しく、版の中の参照は新しい版のノードへ付け替え、処理対象者は同じ対象者を指す)。
     * 分岐の条件は写した値のまま書き、業務モジュールの今の定義に照らし直さない(項目を消した後も複製できる = 定義の管理.md 8 節)。
     *
     * @throws RouteDefinitionViolated 定義の検査に外れたとき
     */
    public function copy(int $versionId, int $sourceVersionId): void
    {
        $sources = WorkflowRouteNode::query()->where('workflow_flow_version_id', $sourceVersionId)->orderBy('position')->get()->keyBy('id');
        $sendBacks = WorkflowRouteSendBack::query()->where('workflow_flow_version_id', $sourceVersionId)->orderBy('id')->get()->groupBy('workflow_route_node_id');
        $edges = WorkflowRouteEdge::query()->where('workflow_flow_version_id', $sourceVersionId)->orderBy('id')->get();
        $code = fn (?int $id) => $id === null ? null : $sources->get($id)?->code;

        $nodes = array_values($sources->map(fn (WorkflowRouteNode $node) => new RouteNodeInput(
            code: $node->code, name: $node->name, kind: $node->kind, targetKind: $node->target_kind, subjectGroupId: $node->authz_subject_group_id,
            relativeBase: $node->relative_base, relativeScope: $node->relative_scope, companyPostId: $node->company_post_id,
            designatorCode: $code($node->designator_workflow_route_node_id), minAssignees: $node->min_assignees, maxAssignees: $node->max_assignees,
            decisionRule: $node->decision_rule, decisionValue: $node->decision_value, preventsApplicantApproval: $node->prevents_applicant_approval,
            closingCode: $code($node->closing_workflow_route_node_id),
            deadlineDays: $node->deadline_days, confirmSubjectGroupId: $node->confirm_authz_subject_group_id,
            sendBackCodes: array_values(array_map(fn (WorkflowRouteSendBack $sendBack) => (string) $code($sendBack->target_workflow_route_node_id), $sendBacks->get($node->id)?->all() ?? [])),
        ))->all());
        $edgeInputs = array_values($edges->map(fn (WorkflowRouteEdge $edge) => new RouteEdgeInput((string) $code($edge->from_workflow_route_node_id), (string) $code($edge->to_workflow_route_node_id), $edge->sort_key, $edge->label, $edge->condition))->all());

        $this->store($versionId, $nodes, $edgeInputs, array_map(fn (RouteEdgeInput $edge) => $edge->condition, $edgeInputs));
    }

    /**
     * @param  list<RouteNodeInput>  $nodes
     * @param  list<RouteEdgeInput>  $edges
     * @param  array<int, array<array-key, mixed>|null>  $conditions  辺の添字 → 書く条件
     */
    private function store(int $versionId, array $nodes, array $edges, array $conditions): void
    {
        $ids = $this->writeNodes($versionId, $nodes);

        foreach ($edges as $index => $edge) {
            WorkflowRouteEdge::query()->create([
                'workflow_flow_version_id' => $versionId, 'from_workflow_route_node_id' => $ids[$edge->fromCode], 'to_workflow_route_node_id' => $ids[$edge->toCode],
                'sort_key' => $edge->sortKey, 'label' => $edge->label, 'condition' => $conditions[$index] ?? null,
            ]);
        }

        foreach ($nodes as $node) {
            foreach ($node->sendBackCodes as $targetCode) {
                WorkflowRouteSendBack::query()->create(['workflow_flow_version_id' => $versionId, 'workflow_route_node_id' => $ids[$node->code], 'target_workflow_route_node_id' => $ids[$targetCode]]);
            }
        }

        $violations = $this->validator->violations($versionId);

        if ($violations !== []) {
            throw new RouteDefinitionViolated($versionId, $violations);
        }
    }

    /**
     * ノードを参照される側から順に書く(位置 = 入力の並びの順)。
     *
     * @param  list<RouteNodeInput>  $nodes
     * @return array<string, int> コード → 書いたノードの id
     */
    private function writeNodes(int $versionId, array $nodes): array
    {
        $byCode = [];
        $positions = [];

        foreach ($nodes as $index => $node) {
            $byCode[$node->code] = $node;
            $positions[$node->code] = $index + 1;
        }

        $ids = [];

        foreach ($this->order->order(array_map(fn (RouteNodeInput $node) => $node->referencedCodes(), $byCode)) as $code) {
            $node = $byCode[$code];
            $ids[$code] = WorkflowRouteNode::query()->create([
                'workflow_flow_version_id' => $versionId, 'code' => $node->code, 'name' => $node->name, 'kind' => $node->kind, 'position' => $positions[$code],
                'target_kind' => $node->targetKind, 'authz_subject_group_id' => $this->subjectGroupOf($node),
                'relative_base' => $node->relativeBase, 'relative_scope' => $node->relativeScope, 'company_post_id' => $node->companyPostId,
                'designator_workflow_route_node_id' => $node->designatorCode === null ? null : $ids[$node->designatorCode],
                'min_assignees' => $node->minAssignees, 'max_assignees' => $node->maxAssignees, 'decision_rule' => $node->decisionRule, 'decision_value' => $node->decisionValue,
                'prevents_applicant_approval' => $node->preventsApplicantApproval,
                'deadline_days' => $node->deadlineDays,
                'confirm_authz_subject_group_id' => $node->confirmSubjectGroupId,
                'closing_workflow_route_node_id' => $node->closingCode === null ? null : $ids[$node->closingCode],
            ])->id;
        }

        return $ids;
    }

    /**
     * 対象者で指定の対象者(既にある対象者か、条件から専用の対象者を作る)。対象者で指定でなければ null。
     */
    private function subjectGroupOf(RouteNodeInput $node): ?int
    {
        if ($node->targetKind !== WorkflowTargetKind::Fixed) {
            return null;
        }

        return $node->subjectGroupId ?? $this->createSelection->execute($node->targetConditions, $node->targetTranslations);
    }
}
