<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowOperation;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\RouteAuthorization;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * 詳細の申請操作の面の props operation(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.2 節、画面/案件の画面.md の 4 節)。
 * ボタン = 案件のフローの operate ∧ そのボタンの管理のルートの execute(保留解除は operate だけ)∧ 状態 × 操作の表(認可と一覧.md の 3 節)。
 * 表は業務処理の判定と同じ {@see CaseOperationDecider::permitsIn()}、表の外の前提は {@see DesignatorNodeGuard}・{@see ReassignCandidates}、
 * 人数の上限は {@see RetargetUserLimit}、展開し直す人は {@see ReexpandedTargets}、
 * 戻り先の候補は {@see SendBackCandidates::ancestorCandidatesOf()} を引く(画面に出たボタンを押して表の 422 にならない)。
 * ダイアログの中身はボタンが真の操作だけ持つ(偽なら空)。どのボタンも偽なら null(面を出さない)。鍵を取らない。
 *
 * @phpstan-import-type LinkedPerson from WorkflowPeople
 *
 * @phpstan-type PanelButtons array{
 *     retarget: bool, reexpand: bool, move: bool, assign: bool, release: bool, destroy: bool,
 * }
 * @phpstan-type RetargetNode array{
 *     nodeId: int, name: string, single: bool, max: int, onHold: bool, targets: list<LinkedPerson>,
 * }
 * @phpstan-type ReexpandNode array{
 *     nodeId: int, name: string, onHold: bool, targets: list<LinkedPerson>, expanded: list<LinkedPerson>,
 *     summary: string|null,
 * }
 * @phpstan-type BackNode array{nodeId: int, name: string}
 * @phpstan-type ReleaseNode array{nodeId: int, name: string}
 * @phpstan-type MoveNode array{nodeId: int, name: string, onHold: bool, canForward: bool, backNodes: list<BackNode>,
 *     defaultBackNodeId: int|null}
 * @phpstan-type AssignNode array{nodeId: int, name: string, kindLabel: string, min: int, max: int, ordered: bool,
 *     assigned: list<LinkedPerson>}
 * @phpstan-type Panel array{caseName: string, buttons: PanelButtons, retargetNodes: list<RetargetNode>,
 *     reexpandNodes: list<ReexpandNode>, moveNodes: list<MoveNode>, assignNodes: list<AssignNode>,
 *     releaseNodes: list<ReleaseNode>}
 */
class CaseOperationPanel
{
    /** 管理のルートの名前の接頭辞(認可と一覧.md の 4 節) */
    private const ROUTE_PREFIX = 'workflow.admin.cases.';

    public function __construct(
        private FlowPermissions $flowPermissions,
        private RouteAuthorization $routes,
        private CaseOperationDecider $decider,
        private ReexpandedTargets $reexpanded,
        private DesignatorNodeGuard $designatorGuard,
        private ReassignCandidates $reassignCandidates,
        private SendBackCandidates $sendBackCandidates,
        private CurrentAssignments $assignments,
        private WorkflowPeople $people,
        private StepTargets $targets,
    ) {}

    /**
     * @param  CaseSituation  $situation  自分を処理者として読んだ状況(業務処理の判定と同じ読み方)
     * @param  Collection<int, WorkflowCaseNode>  $nodes  案件のノード(展開の行は親を読み込んである)
     * @return Panel|null
     */
    public function of(
        Requester $requester,
        AccessContext $context,
        CaseSituation $situation,
        Collection $nodes,
    ): ?array {
        $case = $situation->case;

        if (! $this->flowPermissions->canOperate($requester, $context->date, $case->workflow_flow_id)) {
            return null;
        }

        $choices = $this->choicesOf($requester, $context, $situation);
        $buttons = [
            'retarget' => $choices['retarget'] !== [],
            'reexpand' => $choices['reexpand'] !== [],
            'move' => $choices['move'] !== [],
            'assign' => $choices['assign'] !== [],
            'release' => $choices['release'] !== [],
            'destroy' => $choices['destroy'],
        ];

        if (! in_array(true, $buttons, true)) {
            return null;
        }

        return [
            'caseName' => $case->name ?? throw new LogicException("申請した案件 {$case->id} が案件名を持たない"),
            'buttons' => $buttons,
            ...$this->dialogsOf($context, $case, $choices, $nodes),
        ];
    }

    /**
     * ダイアログの中身(ボタンが真の操作だけ。偽なら空の並び)。
     *
     * @param  array{retarget: list<CurrentNode>, reexpand: list<CurrentNode>, move: list<CurrentNode>,
     *     assign: list<WorkflowCaseNode>, release: list<CurrentNode>, destroy: bool}  $choices
     * @param  Collection<int, WorkflowCaseNode>  $nodes
     * @return array{retargetNodes: list<RetargetNode>, reexpandNodes: list<ReexpandNode>, moveNodes: list<MoveNode>,
     *     assignNodes: list<AssignNode>, releaseNodes: list<ReleaseNode>}
     */
    private function dialogsOf(AccessContext $context, WorkflowCase $case, array $choices, Collection $nodes): array
    {
        $byId = $nodes->keyBy('id');
        $reexpandOf = fn (CurrentNode $node) => $this->reexpanded->of(
            $case,
            $byId->get($node->id) ?? throw new LogicException("案件 {$case->id} にノード {$node->id} が無い"),
        );
        $reexpanded = array_map($reexpandOf, $choices['reexpand']);
        $assigned = $choices['assign'] === [] ? [] : $this->assignments->of($case->id);
        $people = $this->peopleOf($context, [...$choices['retarget'], ...$choices['reexpand']], $reexpanded, $assigned);
        $summaries = $this->summariesOf($case, $choices['reexpand'], $byId, $context->localeCode);
        $reexpandNodeOf = fn (int $index) => $this->reexpandNodeOf(
            $choices['reexpand'][$index],
            $reexpanded[$index],
            $people,
            $summaries[$choices['reexpand'][$index]->id] ?? null,
        );

        return [
            'retargetNodes' => array_map(
                fn (CurrentNode $node) => $this->retargetNodeOf($node, $people),
                $choices['retarget'],
            ),
            'reexpandNodes' => array_map($reexpandNodeOf, array_keys($choices['reexpand'])),
            'moveNodes' => array_map(fn (CurrentNode $node) => $this->moveNodeOf($node), $choices['move']),
            'assignNodes' => array_map(
                fn (WorkflowCaseNode $node) => $this->assignNodeOf($node, $assigned, $people),
                $choices['assign'],
            ),
            // 保留解除は案件の側の処理のダイアログで送る(管理者が処理対象者でなければ nodeActions に行が無いので、開くステップを面が持つ)
            'releaseNodes' => array_map(
                fn (CurrentNode $node) => ['nodeId' => $node->id, 'name' => $node->name],
                $choices['release'],
            ),
        ];
    }

    /**
     * 操作ごとの、表が許す対象(ルートの execute が偽なら空・偽)。ノードを受ける操作は表が許す現在のノード、指定の変更は前提に合うノード、
     * 保留解除はルートを見ない(案件の側のルート)。3 つの移動の表の行は前提が同じ(15.2 節)なので、戻るの行で引く。
     *
     * @return array{retarget: list<CurrentNode>, reexpand: list<CurrentNode>, move: list<CurrentNode>,
     *     assign: list<WorkflowCaseNode>, release: list<CurrentNode>, destroy: bool}
     */
    private function choicesOf(Requester $requester, AccessContext $context, CaseSituation $situation): array
    {
        $date = $context->date;
        $permits = fn (WorkflowOperation $operation, ?int $nodeId) => $this->decider
            ->permitsIn($operation, $requester, $date, $situation, $nodeId, canOperate: true);
        $permitted = fn (WorkflowOperation $operation) => array_values(array_filter(
            $situation->currentNodes,
            fn (CurrentNode $node) => $permits($operation, $node->id),
        ));
        $route = fn (string $name) => $this->routes->allows($requester, self::ROUTE_PREFIX.$name, $date);
        $nodesFor = fn (WorkflowOperation $operation, string $name) => $route($name) ? $permitted($operation) : [];
        $caseAllows = fn (WorkflowOperation $operation, string $name) => $route($name) && $permits($operation, null);

        return [
            'retarget' => $nodesFor(WorkflowOperation::Retarget, 'retarget'),
            'reexpand' => $nodesFor(WorkflowOperation::Reexpand, 'reexpand'),
            'move' => $nodesFor(WorkflowOperation::MoveBack, 'move'),
            'assign' => $caseAllows(WorkflowOperation::Reassign, 'assign')
                ? $this->reassignCandidates->reassignableNodesOf($situation->case->id) : [],
            'release' => $permitted(WorkflowOperation::Release),
            'destroy' => $caseAllows(WorkflowOperation::DeleteCase, 'destroy'),
        ];
    }

    /**
     * 面のダイアログが出す人(今の処理担当者・展開し直した人・今の指定)の表示を 1 回で引く。
     *
     * @param  list<CurrentNode>  $currentNodes
     * @param  list<list<int>>  $reexpanded
     * @param  array<int, list<int>>  $assigned
     * @return array<int, LinkedPerson>
     */
    private function peopleOf(AccessContext $context, array $currentNodes, array $reexpanded, array $assigned): array
    {
        $targets = array_map(fn (CurrentNode $node) => $node->targetUserIds, $currentNodes);
        $userIds = array_merge([], ...$targets, ...$reexpanded, ...array_values($assigned));
        $people = $this->people->users($userIds, $context->date, $context->localeCode);

        return $this->people->linked($people, $context->date, $context->localeCode);
    }

    /**
     * @param  array<int, LinkedPerson>  $people
     * @return RetargetNode
     */
    private function retargetNodeOf(CurrentNode $node, array $people): array
    {
        return [
            'nodeId' => $node->id,
            'name' => $node->name,
            'single' => RetargetUserLimit::isChild($node->parentId),
            'max' => RetargetUserLimit::maxOf($node->parentId),
            'onHold' => $node->state === WorkflowNodeState::Hold,
            'targets' => self::peopleIn($node->targetUserIds, $people),
        ];
    }

    /**
     * @param  list<int>  $userIds  展開し直した人(0 人なら空)
     * @param  array<int, LinkedPerson>  $people
     * @return ReexpandNode
     */
    private function reexpandNodeOf(CurrentNode $node, array $userIds, array $people, ?string $summary): array
    {
        return [
            'nodeId' => $node->id,
            'name' => $node->name,
            'onHold' => $node->state === WorkflowNodeState::Hold,
            'targets' => self::peopleIn($node->targetUserIds, $people),
            'expanded' => self::peopleIn($userIds, $people),
            'summary' => $summary,
        ];
    }

    /**
     * 再展開の「?」の {要約} = 案件のノードの設定から作る要約の可変部(対象者で指定・基準にする人で指定の親のノードだけ。名前は申請基準日。
     * 処理の時に指定するノードと展開の行は null = 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 9.3 節)。
     *
     * @param  list<CurrentNode>  $currentNodes
     * @param  Collection<int, WorkflowCaseNode>  $byId  id をキーにした案件のノード
     * @return array<int, string> ノードの id → 可変部
     */
    private function summariesOf(WorkflowCase $case, array $currentNodes, Collection $byId, string $localeCode): array
    {
        $described = array_values(array_filter(
            array_map(fn (CurrentNode $node) => $byId->get($node->id), $currentNodes),
            fn (?WorkflowCaseNode $node) => $node !== null && $node->parent_workflow_case_node_id === null
                && in_array($node->target_kind, [WorkflowTargetKind::Fixed, WorkflowTargetKind::Relative], true),
        ));

        if ($described === []) {
            return [];
        }

        $date = $case->base_date ?? throw new LogicException("未完了の案件 {$case->id} に申請基準日が無い");
        $targets = array_filter($this->targets->of($described, [], $date, $localeCode));

        return array_map(fn (StepTarget $target) => StepDescriptions::targetPhrase($target), $targets);
    }

    /**
     * 戻り先の候補 = 祖先の候補の全部(写した並び)、既定 = 直前に当たる最後の候補(15.5 節・管理と通知の画面.md の 7.2 節)。
     *
     * @return MoveNode
     */
    private function moveNodeOf(CurrentNode $node): array
    {
        $candidates = $this->sendBackCandidates->ancestorCandidatesOf($node->id);
        $backOf = fn (WorkflowCaseNode $back) => ['nodeId' => $back->id, 'name' => $back->name];
        $backNodes = array_map($backOf, $candidates);

        return [
            'nodeId' => $node->id,
            'name' => $node->name,
            'onHold' => $node->state === WorkflowNodeState::Hold,
            'canForward' => ! $this->designatorGuard->designatesAnother($node->id),
            'backNodes' => $backNodes,
            'defaultBackNodeId' => $backNodes === [] ? null : $backNodes[array_key_last($backNodes)]['nodeId'],
        ];
    }

    /**
     * 指定を変更できるステップ(種類の語 = 承認のダイアログの指定の欄と同じ {@see CaseDesignations::KIND_LABELS})。
     * 今の指定 = {@see CurrentAssignments::of()}。
     *
     * @param  array<int, list<int>>  $assigned  Y の id → 今の指定の人
     * @param  array<int, LinkedPerson>  $people
     * @return AssignNode
     */
    private function assignNodeOf(WorkflowCaseNode $node, array $assigned, array $people): array
    {
        $kind = $node->kind->value;
        $kindLabel = CaseDesignations::KIND_LABELS[$kind]
            ?? throw new LogicException("指定されるノード {$node->id} の種類 {$kind} に語が無い");

        return [
            'nodeId' => $node->id,
            'name' => $node->name,
            'kindLabel' => $kindLabel,
            'min' => $node->min_assignees ?? throw new LogicException("指定されるノード {$node->id} に人数が無い"),
            'max' => $node->max_assignees ?? throw new LogicException("指定されるノード {$node->id} に人数が無い"),
            'ordered' => $node->kind === WorkflowNodeKind::Serial,
            'assigned' => self::peopleIn($assigned[$node->id] ?? [], $people),
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @param  array<int, LinkedPerson>  $people
     * @return list<LinkedPerson>
     */
    private static function peopleIn(array $userIds, array $people): array
    {
        return array_map(fn (int $userId) => $people[$userId], $userIds);
    }
}
