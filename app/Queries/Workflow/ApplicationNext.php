<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteEdge;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Master\Api\CurrentDepartment;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * 申請の画面の次の処理対象者と申請基準日の範囲(docs の 設計/ワークフロー/ルートの深さ/画面/案件の側の画面.md 5 節の next・baseDateRange)。
 * next.nodes = 申請のノードから分岐開始を評価せずに届く、最初に到達するノード(同期開始なら枝ごと。分岐終了・同期終了では止まる = 申請の時点で区間は済んでいない)の
 * 今の展開(新規・一時保存 = 申請基準日の版、再申請 = 案件の写し)。並び = 位置の順。展開は案件の到達と同じ口 {@see NodeTargetExpansion::expand()}
 * (起点の組織 = 担当組織、前処理者の起点の組織 = {@see CaseActor} の口)を申請基準日で呼ぶ(処理対象者.md 4.1〜4.3)。
 * noVersionMessage = 版が無いときの文(申請基準日の日付入り。業務処理の 422 base_date と同じ文 = {@see EffectiveFlowVersions::NO_VERSION_MESSAGE}。版があれば null)。
 * blockedReason = 申請者承認防止のノードの展開が申請者として扱う人だけのときの文言(業務処理の 422 と同じ = 4.6。0 人は画面が 設計/ワークフロー/画面/申請と案件.md 3 節の文で示す)。鍵を取らない。
 *
 * @phpstan-import-type LinkedPerson from WorkflowPeople
 *
 * @phpstan-type Next array{version: int|null, noVersionMessage: string|null, departmentName: string|null, nodes: list<array{name: string, targets: list<LinkedPerson>, blockedReason: string|null}>, dependsOnContent: bool}
 */
class ApplicationNext
{
    public function __construct(
        private NodeTargetExpansion $expansion,
        private CurrentDepartment $currentDepartment,
        private WorkflowPeople $people,
        private EffectiveFlowVersions $versions,
        private ApplicantApprovalGuard $applicantGuard,
    ) {}

    /**
     * 新規・一時保存: 申請基準日の版で、判定を求める者(申請者)のその日のカレント組織を担当組織にして展開する(版が無ければ version = null・nodes = 空)。
     *
     * @return Next
     */
    public function ofFlow(AccessContext $context, int $flowId, CarbonImmutable $baseDate): array
    {
        $applicantId = $context->userId ?? throw new LogicException('申請できる人の判定を通った利用者が無い');
        $departmentId = $this->currentDepartment->departmentIdOn($applicantId, $context->selectedDepartmentId, $baseDate);
        $departmentName = $this->departmentName($departmentId, $baseDate, $context->localeCode);
        $versionId = $this->versions->idOn($flowId, $baseDate);

        if ($versionId === null) {
            return ['version' => null, 'noVersionMessage' => sprintf(EffectiveFlowVersions::NO_VERSION_MESSAGE, $baseDate->format(ApplicationBaseDates::FORMAT)), 'departmentName' => $departmentName, 'nodes' => [], 'dependsOnContent' => false];
        }

        $nodes = WorkflowRouteNode::query()->where('workflow_flow_version_id', $versionId)->get()->keyBy('id')->all();
        $edges = WorkflowRouteEdge::query()->where('workflow_flow_version_id', $versionId)->get()
            ->map(fn (WorkflowRouteEdge $edge) => [$edge->from_workflow_route_node_id, $edge->to_workflow_route_node_id])->all();
        $previous = $this->previousDepartmentId($context, $baseDate, $applicantId, $departmentId);
        $origin = ['department' => $departmentId, 'previous' => $previous, 'applicants' => $this->applicantGuard->applicantUserIdsOf($applicantId, $flowId)];

        return [
            'version' => WorkflowFlowVersion::query()->findOrFail($versionId)->number,
            'noVersionMessage' => null,
            'departmentName' => $departmentName,
            ...$this->reached($nodes, array_values($edges), $origin, $baseDate, $context),
        ];
    }

    /**
     * 再申請: 案件の写し・申請基準日・担当組織で展開する(再申請は申請基準日と版を変えない = 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.1)。前処理者 = 案件の申請者。
     *
     * @return Next
     */
    public function ofCase(AccessContext $context, WorkflowCase $case): array
    {
        $baseDate = $case->base_date ?? throw new LogicException("申請した案件 {$case->id} に申請基準日が無い");
        $nodes = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->whereNull('parent_workflow_case_node_id')->get()->keyBy('id')->all();
        $edges = WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->get()
            ->map(fn (WorkflowCaseEdge $edge) => [$edge->from_workflow_case_node_id, $edge->to_workflow_case_node_id])->all();
        $previous = $this->previousDepartmentId($context, $baseDate, $case->user_id, $case->department_id);
        $origin = ['department' => $case->department_id, 'previous' => $previous, 'applicants' => $this->applicantGuard->applicantUserIds($case)];

        return [
            'version' => $case->workflowFlowVersion()->firstOrFail()->number,
            'noVersionMessage' => null,
            'departmentName' => $this->departmentName($case->department_id, $baseDate, $context->localeCode),
            ...$this->reached($nodes, array_values($edges), $origin, $baseDate, $context),
        ];
    }

    /**
     * 申請基準日に選べる範囲(設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.1): 最小 = 有効な版の最初の開始日と今日の 1 年前の遅い方、最大 = 有効な版の最後の日と今日の 1 年後の早い方
     * (今日の前後 1 年 = {@see ApplicationBaseDates})。有効な版が無ければ両方 null。範囲の中の無効の版の期間は止めない(業務処理の 422)。
     *
     * @return array{min: string|null, max: string|null}
     */
    public function baseDateRange(int $flowId, CarbonImmutable $today): array
    {
        $versions = WorkflowFlowVersion::query()->where('workflow_flow_id', $flowId)->where('is_deleted', false)->orderBy('number')->get();
        $first = $versions->first();
        $last = $versions->last();

        if ($first === null || $last === null) {
            return ['min' => null, 'max' => null];
        }

        $format = ApplicationBaseDates::FORMAT;
        $min = max($first->start_date->format($format), ApplicationBaseDates::earliest($today)->format($format));
        $latest = ApplicationBaseDates::latest($today)->format($format);
        $max = $last->end_date === null ? $latest : min($last->end_date->toImmutable()->subDay()->format($format), $latest);

        return $min > $max ? ['min' => null, 'max' => null] : ['min' => $min, 'max' => $max];
    }

    /**
     * 申請のノードから分岐開始を評価せずに届く、最初に到達するノードとその展開(同期開始は越えて枝へ進み、分岐開始・分岐終了・同期終了で止める)。
     *
     * @param  array<int, WorkflowRouteNode|WorkflowCaseNode>  $nodes  id → ノード(展開の行を除く)
     * @param  list<array{int, int}>  $edges  [出る id, 入る id]
     * @param  array{department: int|null, previous: int|null, applicants: non-empty-list<int>}  $origin  担当組織・前処理者の起点の組織・申請者として扱う人
     * @return array{nodes: list<array{name: string, targets: list<LinkedPerson>, blockedReason: string|null}>, dependsOnContent: bool}
     */
    private function reached(array $nodes, array $edges, array $origin, CarbonImmutable $baseDate, AccessContext $context): array
    {
        $apply = array_values(array_filter($nodes, fn (WorkflowRouteNode|WorkflowCaseNode $node) => $node->kind === WorkflowNodeKind::Apply))[0]
            ?? throw new LogicException('申請のノードが無い');
        $queue = $this->successors($edges, $apply->id);
        $seen = [];
        $reached = [];
        $dependsOnContent = false;

        while ($queue !== []) {
            $node = $nodes[array_shift($queue)];

            if (isset($seen[$node->id])) {
                continue;
            }

            $seen[$node->id] = true;
            match ($node->kind) {
                WorkflowNodeKind::BranchStart => $dependsOnContent = true,
                WorkflowNodeKind::SyncStart => array_push($queue, ...$this->successors($edges, $node->id)),
                WorkflowNodeKind::SyncEnd, WorkflowNodeKind::BranchEnd => null,
                default => $reached[] = $node,
            };
        }

        usort($reached, fn (WorkflowRouteNode|WorkflowCaseNode $a, WorkflowRouteNode|WorkflowCaseNode $b) => [$a->position, $a->id] <=> [$b->position, $b->id]);
        $targets = array_map(fn (WorkflowRouteNode|WorkflowCaseNode $node) => $this->expansion->expand($node, $origin['department'], $origin['previous'], $baseDate), $reached);
        $people = $this->people->users(array_merge([], ...$targets), $baseDate, $context->localeCode);
        $people = $this->people->linked($people, $context->date, $context->localeCode);

        return [
            'nodes' => array_map(fn (WorkflowRouteNode|WorkflowCaseNode $node, array $ids) => [
                'name' => $node->name,
                'targets' => array_map(fn (int $id) => $people[$id], $ids),
                'blockedReason' => $this->applicantGuard->onlyApplicants($node->prevents_applicant_approval, $ids, $origin['applicants'])
                    ? ApplicantApprovalGuard::onlyApplicantTargetsMessage($node->name, $ids, $origin['applicants'][0])
                    : null,
            ], $reached, $targets),
            'dependsOnContent' => $dependsOnContent,
        ];
    }

    /**
     * @param  list<array{int, int}>  $edges
     * @return list<int>
     */
    private function successors(array $edges, int $nodeId): array
    {
        return array_values(array_map(fn (array $edge) => $edge[1], array_filter($edges, fn (array $edge) => $edge[0] === $nodeId)));
    }

    /**
     * 前処理者の起点の組織。申請のノードを処理するのは申請者なので、処理者 = 申請者として、申請・再申請の計画と同じ前処理者と起点の組織にする
     * (設計/ワークフロー/案件の運用/案件の操作/README.md の 3.2 節・4.5 節。{@see NodeTargetExpansion::originDepartmentOn()})。
     */
    private function previousDepartmentId(
        AccessContext $context,
        CarbonImmutable $baseDate,
        int $applicantUserId,
        ?int $applicantDepartmentId,
    ): ?int {
        $actor = CaseActor::forProcessor($applicantUserId, $context);
        $selected = $actor->previousSelectedDepartmentId($context, $applicantUserId, $applicantDepartmentId);

        return $this->expansion->originDepartmentOn($actor->previousUserId(), $selected, $baseDate);
    }

    private function departmentName(?int $departmentId, CarbonImmutable $date, string $localeCode): ?string
    {
        if ($departmentId === null) {
            return null;
        }

        $department = $this->people->departments([$departmentId], $date, $localeCode)[$departmentId];

        return $department['name'] ?? $department['code'];
    }
}
