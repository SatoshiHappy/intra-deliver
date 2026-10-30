<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseRowField;
use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;
use App\Support\PageNumbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 申請操作の一覧のページ Workflow/Admin/CaseOperations の props(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.3 節、
 * 形は 画面/管理と通知の画面.md の 7.1 節)。出る案件 = 管理の述語({@see CaseVisibility::managed()})、検索の語 = {@see CaseKeywords}、
 * 期限超過 = {@see CaseDeadlines::whereOverdue()}、行 = {@see CaseRows}(経過日数・期限の対象は現在のノードの全部)。
 * 見てよい画面かはルートの認可が決める(案件の運用/認可と一覧.md の 4 節)。問い合わせの回数は行の数に依らない。
 *
 * @phpstan-import-type CaseRow from CaseRows
 *
 * @phpstan-type Flow array{code: string, name: string}
 * @phpstan-type OperationQuery array{flow: string|null, scope: string, q: string, overdue: bool}
 * @phpstan-type OperationList array{
 *     query: OperationQuery, flows: list<Flow>,
 *     cases: array{data: list<CaseRow>, currentPage: int, lastPage: int, total: int},
 * }
 */
class CaseOperationList
{
    /** 1 ページの件数(申請一覧と同じ) */
    public const PER_PAGE = CaseListQuery::PER_PAGE;

    private const ACTIVE = 'active';

    private const COMPLETED = 'completed';

    public function __construct(
        private CaseVisibility $visibility,
        private FlowPermissions $flowPermissions,
        private CaseRows $rows,
        private CaseDeadlines $deadlines,
    ) {}

    /**
     * クエリ flow・scope・q・overdue・page を読む(解釈できない値は既定 = すべてのフロー・未完了・語なし・期限超過で絞らない・1 ページ目)。
     * scope = completed のときは overdue を読まない(完了の案件は期限超過でない)。query は解釈した後の値。
     *
     * @return OperationList
     */
    public function list(
        AccessContext $context,
        mixed $flow,
        mixed $scope,
        mixed $q,
        mixed $overdue,
        mixed $page,
    ): array {
        $requester = Requester::fromAccessContext($context);
        $flows = $this->readableFlows($requester, $context);
        $flowCode = is_string($flow) && isset($flows[$flow]) ? $flow : null;
        $scope = $scope === self::COMPLETED ? self::COMPLETED : self::ACTIVE;
        $overdueOnly = $scope === self::ACTIVE && $overdue === '1';
        $keyword = CaseKeywords::of($q);
        $query = $this->visibility->managed(WorkflowCase::query(), $requester, $context->date)
            ->where('state', $scope === self::COMPLETED ? WorkflowCaseState::Completed : WorkflowCaseState::Active);
        CaseKeywords::apply($query, $keyword, false);

        if ($flowCode !== null) {
            $query->where('workflow_flow_id', $flows[$flowCode]['id']);
        }

        if ($overdueOnly) {
            $query->whereHas('workflowCaseNodes', fn (Builder $nodes) => $this->overdue($nodes, $context));
        }

        $flowOf = fn (array $row) => ['code' => $row['code'], 'name' => $row['name']];

        return [
            'query' => ['flow' => $flowCode, 'scope' => $scope, 'q' => $keyword, 'overdue' => $overdueOnly],
            'flows' => array_values(array_map($flowOf, $flows)),
            'cases' => $this->page($query, $requester, $context, $page),
        ];
    }

    /**
     * @param  Builder<WorkflowCase>  $query
     * @return array{data: list<CaseRow>, currentPage: int, lastPage: int, total: int}
     */
    private function page(Builder $query, Requester $requester, AccessContext $context, mixed $page): array
    {
        $total = $query->toBase()->getCountForPagination();
        $pageNumber = PageNumbers::of($page, $total, self::PER_PAGE);
        $paginator = $query->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(perPage: self::PER_PAGE, page: $pageNumber, total: $total);
        $items = new Collection($paginator->items());
        $fields = [WorkflowCaseRowField::CurrentDeadline];

        return [
            'data' => $this->rows->rows($requester, $context->date, $context->localeCode, $items, false, $fields),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * read を許されるフロー(コード → id・コード・名前。並びはフローの一覧と同じ)。絞り込みの選択肢と flow の読み取りが同じ集合を使う。
     *
     * @return array<string, array{id: int, code: string, name: string}>
     */
    private function readableFlows(Requester $requester, AccessContext $context): array
    {
        $ids = $this->flowPermissions->readableFlowIds($requester, $context->date);
        $flows = WorkflowFlow::query()->whereKey($ids)->orderBy('sort_key')->orderBy('code')->get();

        return $flows->mapWithKeys(fn (WorkflowFlow $flow) => [
            $flow->code => ['id' => $flow->id, 'code' => $flow->code, 'name' => $flow->name],
        ])->all();
    }

    /**
     * @param  Builder<WorkflowCaseNode>  $nodes
     * @return Builder<WorkflowCaseNode>
     */
    private function overdue(Builder $nodes, AccessContext $context): Builder
    {
        return $this->deadlines->whereOverdue($nodes, $context->date);
    }
}
