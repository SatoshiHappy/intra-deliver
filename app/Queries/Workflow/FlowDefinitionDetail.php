<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Master\Api\MasterCandidates;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * フロー定義の詳細(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md 3 節、クエリの既定 = 画面/README.md 1.1 節)。
 * クエリは解釈した後の値を返す(version = 無い・数でない・そのフローに無い番号なら今日の版、無ければ最後の版。tab = 既定 diagram。
 * node = 選んだ版に無ければ申請のノード)。today = サーバーの今日(版を追加の開始日の範囲に使う。遅延にしない)。
 * 図・選んだステップ・参照者・選択の欄の候補は部分の再読み込みのために遅延で評価する。鍵を取らない。
 *
 * @phpstan-import-type SubjectRow from StepTarget
 * @phpstan-import-type StepSettings from FlowStepSettings
 * @phpstan-import-type NodeChoice from FlowStepSettings
 * @phpstan-import-type DefinitionNode from FlowDefinitionDiagram
 * @phpstan-import-type DefinitionEdge from FlowDefinitionDiagram
 *
 * @phpstan-type VersionRow array{number: int, startDate: string, endDate: string|null, displayEndDate: string|null,
 *     isDisabled: bool, isCurrent: bool}
 * @phpstan-type Detail array{flow: array{code: string, name: string, moduleName: string}, versions: list<VersionRow>,
 *     selectedVersion: int, tab: string, today: string,
 *     diagram: Closure(): array{nodes: list<DefinitionNode>, edges: list<DefinitionEdge>},
 *     selectedNode: Closure(): StepSettings, referrers: Closure(): array{rows: list<SubjectRow>},
 *     options: Closure(): array{companyPosts: list<array{id: int, name: string}>, designators: list<NodeChoice>}}
 */
class FlowDefinitionDetail
{
    /** タブ(先頭が既定) */
    private const TABS = ['diagram', 'versions', 'referrers'];

    public function __construct(
        private EffectiveFlowVersions $versions,
        private FlowDefinitionDiagram $diagram,
        private FlowStepSettings $settings,
        private SubjectRows $subjectRows,
        private MasterCandidates $master,
    ) {}

    /**
     * @param  mixed  $version  クエリ version
     * @param  mixed  $tab  クエリ tab
     * @param  mixed  $node  クエリ node
     * @return Detail
     *
     * @throws ModelNotFoundException<WorkflowFlow> フローが無いとき
     */
    public function show(
        string $flowCode,
        mixed $version,
        mixed $tab,
        mixed $node,
        DateTimeInterface $today,
        string $localeCode,
    ): array {
        $flow = WorkflowFlow::query()->with('workflowContent.recordModule')->where('code', $flowCode)->firstOrFail();
        $versions = WorkflowFlowVersion::query()->where('workflow_flow_id', $flow->id)->orderByDesc('number')->get();
        $currentId = $this->versions->idOn($flow->id, $today);
        $selected = self::selectedVersion($versions, $version, $currentId);
        $route = null;
        $routeOf = function () use (&$route, $selected): FlowVersionRoute {
            return $route ??= FlowVersionRoute::load($selected->id);
        };
        $nodeOf = fn () => self::selectedNode($routeOf(), $node);

        return [
            'flow' => [
                'code' => $flow->code,
                'name' => $flow->name,
                'moduleName' => $flow->workflowContent->recordModule->name
                    ?? throw new LogicException("フロー {$flow->code} のコンテンツの業務モジュールが無い"),
            ],
            'versions' => array_values($versions->map(fn (WorkflowFlowVersion $row) => [
                'number' => $row->number,
                'startDate' => $row->start_date->toDateString(),
                'endDate' => $row->end_date?->toDateString(),
                'displayEndDate' => FlowDefinitionList::displayEndDateOf($row),
                'isDisabled' => $row->is_deleted,
                'isCurrent' => $row->id === $currentId,
            ])->all()),
            'selectedVersion' => $selected->number,
            'tab' => is_string($tab) && in_array($tab, self::TABS, true) ? $tab : self::TABS[0],
            'today' => $today->format('Y-m-d'),
            'diagram' => fn () => $this->diagram->of($routeOf(), $today, $localeCode),
            'selectedNode' => fn () => $this->settings->of($routeOf(), $nodeOf(), $today, $localeCode),
            'referrers' => fn () => ['rows' => $this->referrerRows($flow, $today, $localeCode)],
            'options' => fn () => [
                'companyPosts' => $this->companyPosts($today, $localeCode),
                'designators' => $this->settings->designatorsOf($routeOf(), $nodeOf()),
            ],
        ];
    }

    /**
     * @param  Collection<int, WorkflowFlowVersion>  $versions  番号の降順
     */
    private static function selectedVersion(Collection $versions, mixed $number, ?int $currentId): WorkflowFlowVersion
    {
        $isNumber = is_string($number) && ctype_digit($number) && strlen($number) <= 9;
        $chosen = $isNumber ? $versions->first(fn (WorkflowFlowVersion $row) => $row->number === (int) $number) : null;

        return $chosen
            ?? $versions->first(fn (WorkflowFlowVersion $row) => $row->id === $currentId)
            ?? $versions->first()
            ?? throw new LogicException('フローに版が無い');
    }

    private static function selectedNode(FlowVersionRoute $route, mixed $code): WorkflowRouteNode
    {
        $chosen = is_string($code) ? $route->nodeByCode($code) : null;

        $isApply = fn (WorkflowRouteNode $node) => $node->kind === WorkflowNodeKind::Apply;

        return $chosen ?? collect($route->nodes)->first($isApply) ?? throw new LogicException('版に申請のノードが無い');
    }

    /**
     * @return list<SubjectRow>
     */
    private function referrerRows(WorkflowFlow $flow, DateTimeInterface $today, string $localeCode): array
    {
        $groupId = $flow->authz_subject_group_id;

        if ($groupId === null) {
            return [];
        }

        return $this->subjectRows->of([(int) $groupId], $today, $localeCode)[(int) $groupId] ?? [];
    }

    /**
     * 今日に有効な役職(並び = 共通マスタの候補の口の並び。名前の無い役職は「#{id}」)。
     *
     * @return list<array{id: int, name: string}>
     */
    private function companyPosts(DateTimeInterface $today, string $localeCode): array
    {
        return array_map(
            fn (array $post) => ['id' => $post['id'], 'name' => $post['name'] ?? "#{$post['id']}"],
            $this->master->effectiveCompanyPostsOn($today, $localeCode),
        );
    }
}
