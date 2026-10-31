<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseRowField;
use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;
use App\Support\PageNumbers;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 案件一覧の 6 タブ(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.1 節、形は 画面/案件の画面.md の 2 節の Props)。タブの述語と件数は
 * 閲覧の判定 {@see CaseVisibility} の絞り込み、検索の語は {@see CaseKeywords}。行の追加の項目はタブごとに求める(2.2 節)。
 * 問い合わせの回数は行の数に依らない。
 *
 * @phpstan-import-type CaseRow from CaseRows
 * @phpstan-import-type AttachmentLimits from WorkflowAttachmentLimits
 * @phpstan-import-type Links from PersonLinks
 *
 * @phpstan-type CaseList array{
 *     query: array{tab: string, scope: string, q: string}, counts: Counts,
 *     cases: array{data: list<CaseRow>, currentPage: int, lastPage: int, total: int}, commentMaxLength: int,
 *     attachmentLimits: AttachmentLimits|null, links: Closure(): Links,
 * }
 * @phpstan-type Counts array{draft: int, pending: int, unconfirmed: int}
 */
class CaseListQuery
{
    /** 1 ページの件数 */
    public const PER_PAGE = 20;

    private const TABS = ['draft', 'pending', 'unconfirmed', 'processed', 'confirmed', 'referenced'];

    private const DEFAULT_TAB = 'pending';

    /** 切替(scope)を読むタブ */
    private const SCOPED_TABS = ['processed', 'confirmed', 'referenced'];

    private const SCOPES = ['active', 'completed'];

    private const DEFAULT_SCOPE = 'active';

    /** タブごとに行に求める追加の項目(ほかのタブは求めない) */
    private const FIELDS = [
        'pending' => [
            WorkflowCaseRowField::Deadline,
            WorkflowCaseRowField::Principals,
            WorkflowCaseRowField::BulkApproval,
        ],
        'unconfirmed' => [WorkflowCaseRowField::OpenConfirmation],
        'confirmed' => [WorkflowCaseRowField::DoneConfirmation],
    ];

    public function __construct(
        private CaseVisibility $visibility,
        private CaseRows $rows,
        private WorkflowAttachmentLimits $limits,
        private PersonLinks $links,
    ) {}

    /**
     * @param  mixed  $tab  クエリの tab
     * @param  mixed  $scope  クエリの scope
     * @param  mixed  $page  クエリの page
     * @param  mixed  $q  クエリの q
     * @return CaseList
     */
    public function list(AccessContext $context, mixed $tab, mixed $scope, mixed $page, mixed $q = null): array
    {
        $requester = Requester::fromAccessContext($context);
        $tab = is_string($tab) && in_array($tab, self::TABS, true) ? $tab : self::DEFAULT_TAB;
        $isScoped = in_array($tab, self::SCOPED_TABS, true);
        $scope = $isScoped && is_string($scope) && in_array($scope, self::SCOPES, true) ? $scope : self::DEFAULT_SCOPE;
        $keyword = CaseKeywords::of($q);
        $query = $this->tabQuery($tab, $requester, $context);
        CaseKeywords::apply($query, $keyword, $tab === 'draft');

        if ($isScoped) {
            $query->where('state', $scope === 'completed' ? WorkflowCaseState::Completed : WorkflowCaseState::Active);
        }

        $total = $query->toBase()->getCountForPagination();
        $pageNumber = PageNumbers::of($page, $total, self::PER_PAGE);
        $paginator = $query->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(perPage: self::PER_PAGE, page: $pageNumber, total: $total);
        $items = new Collection($paginator->items());
        $fields = self::FIELDS[$tab] ?? [];
        $rows = $this->rows->rows($requester, $context->date, $context->localeCode, $items, true, $fields);

        return [
            'query' => ['tab' => $tab, 'scope' => $scope, 'q' => $keyword],
            'counts' => $this->counts($context),
            'cases' => [
                'data' => $rows,
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'commentMaxLength' => WorkflowCaseHistory::COMMENT_MAX,
            'attachmentLimits' => $this->limits->forPage($this->anyAttachable($rows)),
            'links' => $this->links->lazy($context),
        ];
    }

    /**
     * どれかの行の入口(引戻し・取止め)が添付を受けるか(ページの attachmentLimits を出すか =
     * 設計/ワークフロー/申請の周辺/添付/README.md の 5 節)。
     *
     * @param  list<CaseRow>  $rows
     */
    private function anyAttachable(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['canAttachOnWithdraw'] || in_array(true, array_column($row['pullBackNodes'], 'canAttach'), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 一時保存・未処理・未確認のタブの件数(案件の数。検索の語に依らない。行を読まない。ホームの件数カードは同じ述語で
     * {@see WorkflowHomeQuery} が数える)。d に有効なユーザーでなければどれも 0。
     *
     * @return Counts
     */
    public function counts(AccessContext $context): array
    {
        $requester = Requester::fromAccessContext($context);
        $count = fn (string $tab) => $this->tabQuery($tab, $requester, $context)->count();

        return ['draft' => $count('draft'), 'pending' => $count('pending'), 'unconfirmed' => $count('unconfirmed')];
    }

    /**
     * タブの述語で絞った問い合わせ。
     *
     * @return Builder<WorkflowCase>
     */
    private function tabQuery(string $tab, Requester $requester, AccessContext $context): Builder
    {
        $query = WorkflowCase::query();
        $date = $context->date;

        return match ($tab) {
            'draft' => $this->visibility->draft($query, $requester, $date),
            'unconfirmed' => $this->visibility->unconfirmed($query, $requester, $date),
            'processed' => $this->visibility->processed($query, $requester, $date),
            'confirmed' => $this->visibility->confirmed($query, $requester, $date),
            'referenced' => $this->visibility->referenced($query, $requester, $date),
            default => $this->visibility->pending($query, $requester, $date),
        };
    }
}
