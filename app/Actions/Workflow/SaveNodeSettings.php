<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Models\Workflow\WorkflowRouteSendBack;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ステップの設定の保存(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 5.1 節。失敗の形 = 同じ文書の 6 節、
 * 鍵 = データモデル/直列化と検査.md の 4.2 節)。
 */
class SaveNodeSettings
{
    /**
     * 規則 → [保存したノードが違反のコードの先頭のとき, 2 つ目以降のとき] の [キー, 文言](null = 422 にしない。
     * コードの並びは {@see RouteViolation})
     */
    private const VIOLATION_ERRORS = [
        'G-9' => [
            ['relative_base', FlowDefinitionErrors::PREVIOUS_BASE_BLOCKED],
            ['min_assignees', FlowDefinitionErrors::SKIPPABLE_MIN_ZERO],
        ],
        'G-10' => [
            ['designator_node_code', FlowDefinitionErrors::DESIGNATOR_INVALID],
            ['min_assignees', FlowDefinitionErrors::DESIGNATING_MIN_ZERO],
        ],
        'G-11' => [['send_back_node_codes', FlowDefinitionErrors::SEND_BACK_INVALID], null],
    ];

    public function __construct(
        private NodeSettingsValidator $settings,
        private SelectionSubjectGroupWriter $subjectGroups,
        private RouteDefinitionValidator $definition,
    ) {}

    /**
     * @throws ModelNotFoundException<WorkflowFlow|WorkflowFlowVersion|WorkflowRouteNode> フロー・そのフローの版・その版のノードが無いとき
     * @throws ValidationException 入力の誤り・保存したノードが関わる定義の検査の違反
     * @throws RouteDefinitionViolated ほかの定義の検査の違反
     */
    public function execute(string $flowCode, int $versionNumber, string $nodeCode, NodeSettingsInput $input): void
    {
        DB::transaction(function () use ($flowCode, $versionNumber, $nodeCode, $input) {
            $flow = WorkflowFlow::query()->where('code', $flowCode)->lock('for no key update')->firstOrFail();
            $version = WorkflowFlowVersion::query()
                ->where('workflow_flow_id', $flow->id)->where('number', $versionNumber)->firstOrFail();
            $nodes = WorkflowRouteNode::query()->where('workflow_flow_version_id', $version->id);
            $nodeIds = (clone $nodes)->pluck('id', 'code')->all();
            $node = $nodes->where('code', $nodeCode)->firstOrFail();
            $subjectGroupId = $this->validateAndRepoint($flow, $node, $input, $nodeIds);

            $this->write($node, $input, $nodeIds, $subjectGroupId);
            $this->throwIfViolated($version->id, $node->code);
        });
    }

    /**
     * 入力を確かめ、指定の種類が fixed で対象者の条件が今と違えば対象者を作ってその id を返す。相手の確かめは対象者の口に
     * 任せるので、相手の誤りをほかの欄の誤りと 1 つの 422 にするためにここで呼ぶ(誤りがあればトランザクションごと戻る)。
     *
     * @param  array<string, int>  $nodeIds
     * @return int|null 指す対象者(fixed でなければ null)
     *
     * @throws ValidationException
     */
    private function validateAndRepoint(
        WorkflowFlow $flow,
        WorkflowRouteNode $node,
        NodeSettingsInput $input,
        array $nodeIds,
    ): ?int {
        $errors = array_map(fn (string $error) => [$error], $this->settings->errors($node->kind, $input, $nodeIds));
        $isFixed = $input->targetKind === WorkflowTargetKind::Fixed;
        $subjectGroupId = null;

        if ($isFixed && ! isset($errors['target_kind']) && ! isset($errors['targets'])) {
            try {
                $name = "{$flow->name} / {$node->name}";
                $subjectGroupId = $this->subjectGroups
                    ->repoint($node->authz_subject_group_id, $input->targets ?? [], $name, 'targets');
            } catch (ValidationException $exception) {
                $errors = [...$errors, ...$exception->errors()];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $subjectGroupId;
    }

    /**
     * ノードの設定の列と差戻しできる先の行を書く。
     *
     * @param  array<string, int>  $nodeIds
     */
    private function write(
        WorkflowRouteNode $node,
        NodeSettingsInput $input,
        array $nodeIds,
        ?int $subjectGroupId,
    ): void {
        $designatorCode = $input->designatorNodeCode;
        $node->update([
            'target_kind' => $input->targetKind, 'authz_subject_group_id' => $subjectGroupId,
            'relative_base' => $input->relativeBase, 'relative_scope' => $input->relativeScope,
            'company_post_id' => $input->companyPostId,
            'designator_workflow_route_node_id' => $designatorCode === null ? null : $nodeIds[$designatorCode],
            'min_assignees' => $input->minAssignees, 'max_assignees' => $input->maxAssignees,
            'prevents_applicant_approval' => $input->preventsApplicantApproval ?? false,
            'decision_rule' => $input->decisionRule, 'decision_value' => $input->decisionValue,
        ]);

        WorkflowRouteSendBack::query()->where('workflow_route_node_id', $node->id)->delete();

        foreach (array_unique($input->sendBackNodeCodes ?? []) as $code) {
            WorkflowRouteSendBack::query()->create([
                'workflow_flow_version_id' => $node->workflow_flow_version_id, 'workflow_route_node_id' => $node->id,
                'target_workflow_route_node_id' => $nodeIds[$code],
            ]);
        }
    }

    /**
     * 定義の検査の違反を、保存したノードの役のキーの 422 にする(定義の管理.md の 3 節)。
     *
     * @throws ValidationException
     * @throws RouteDefinitionViolated
     */
    private function throwIfViolated(int $versionId, string $nodeCode): void
    {
        $violations = $this->definition->violations($versionId);
        $errors = [];

        foreach ($violations as $violation) {
            $error = $this->errorOf($violation, $nodeCode)
                ?? throw new RouteDefinitionViolated($versionId, $violations);
            $errors[$error[0]] ??= [$error[1]];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array{string, string}|null [キー, 文言](422 にしない違反は null)
     */
    private function errorOf(RouteViolation $violation, string $nodeCode): ?array
    {
        $roles = self::VIOLATION_ERRORS[$violation->rule] ?? null;
        $position = array_search($nodeCode, $violation->nodeCodes, true);

        return match (true) {
            $roles === null || $position === false => null,
            $position === 0 => $roles[0],
            default => $roles[1],
        };
    }
}
