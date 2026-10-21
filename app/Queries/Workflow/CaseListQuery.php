<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 案件一覧の 4 タブ(docs の 設計/ワークフロー/認可と一覧.md 3.2、形は 画面.md 4 節の Props)。タブの述語と件数は閲覧の判定 {@see CaseVisibility} の絞り込み。
 * 問い合わせの回数は行の数に依らない。
 *
 * @phpstan-import-type CaseRow from CaseRows
 * @phpstan-import-type AttachmentLimits from WorkflowAttachmentLimits
 */
class CaseListQuery
{
    /** 1 ページの件数 */
    public const PER_PAGE = 20;

    private const TABS = ['draft', 'pending', 'processed', 'referenced'];

    private const DEFAULT_TAB = 'pending';

    /** 切替(scope)を読むタブ */
    private const SCOPED_TABS = ['processed', 'referenced'];

    private const SCOPES = ['active', 'completed'];

    private const DEFAULT_SCOPE = 'active';

    public function __construct(
        private CaseVisibility $visibility,
        private CaseRows $rows,
        private WorkflowAttachmentLimits $limits,
    ) {}

    /**
     * @param  mixed  $tab  クエリの tab
     * @param  mixed  $scope  クエリの scope
     * @param  mixed  $page  クエリの page
     * @return array{query: array{tab: string, scope: string}, counts: array{draft: int, pending: int}, cases: array{data: list<CaseRow>, currentPage: int, lastPage: int, total: int}, commentMaxLength: int,
     *     attachmentLimits: AttachmentLimits|null}
     */
    public function list(AccessContext $context, mixed $tab, mixed $scope, mixed $page): array
    {
        $requester = Requester::fromAccessContext($context);
        $tab = is_string($tab) && in_array($tab, self::TABS, true) ? $tab : self::DEFAULT_TAB;
        $scope = in_array($tab, self::SCOPED_TABS, true) && is_string($scope) && in_array($scope, self::SCOPES, true) ? $scope : self::DEFAULT_SCOPE;
        $query = $this->tabQuery($tab, $requester, $context);

        if (in_array($tab, self::SCOPED_TABS, true)) {
            $query->where('state', $scope === 'completed' ? WorkflowCaseState::Completed : WorkflowCaseState::Active);
        }

        $paginator = $query->orderByDesc('updated_at')->orderByDesc('id')->paginate(perPage: self::PER_PAGE, page: $this->pageOf($page));
        $items = new Collection($paginator->items());
        $rows = $this->rows->rows($requester, $context->date, $context->localeCode, $items, opensDialogs: true);

        return [
            'query' => ['tab' => $tab, 'scope' => $scope],
            'counts' => $this->counts($context),
            'cases' => [
                'data' => $rows,
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'commentMaxLength' => WorkflowCaseHistory::COMMENT_MAX,
            'attachmentLimits' => $this->limits->forPage($this->anyAttachable($rows)),
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
     * 一時保存と未処理のタブの件数(行を読まない。ホームの件数カードは同じ述語で {@see WorkflowHomeQuery} が数える = 画面.md 7 節)。d に有効なユーザーでなければどちらも 0。
     *
     * @return array{draft: int, pending: int}
     */
    public function counts(AccessContext $context): array
    {
        $requester = Requester::fromAccessContext($context);

        return [
            'draft' => $this->visibility->draft(WorkflowCase::query(), $requester, $context->date)->count(),
            'pending' => $this->visibility->pending(WorkflowCase::query(), $requester, $context->date)->count(),
        ];
    }

    /**
     * タブの述語で絞った問い合わせ。
     *
     * @return Builder<WorkflowCase>
     */
    private function tabQuery(string $tab, Requester $requester, AccessContext $context): Builder
    {
        return match ($tab) {
            'draft' => $this->visibility->draft(WorkflowCase::query(), $requester, $context->date),
            'processed' => $this->visibility->processed(WorkflowCase::query(), $requester, $context->date),
            'referenced' => $this->visibility->referenced(WorkflowCase::query(), $requester, $context->date),
            default => $this->visibility->pending(WorkflowCase::query(), $requester, $context->date),
        };
    }

    /**
     * クエリの page(1 から始まる 10 進の整数の文字列だけを読む。業務データの入れ物の一覧と同じ規則)。
     */
    private function pageOf(mixed $page): int
    {
        return is_string($page) && preg_match('/^[1-9][0-9]{0,8}$/D', $page) === 1 ? (int) $page : 1;
    }
}
