<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowProxy;
use App\Queries\Master\Api\MasterNames;
use App\Support\AccessContext;
use App\Support\PageNumbers;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * 代理の設定と代理の管理のページの props(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節・7.4 節)。
 * 代理の設定は自分が代理元・代理先の行だけ(ゲストは空)。代理の管理は全部の行で、見てよい範囲はルートの認可が決める
 * (設計/ワークフロー/案件の運用/認可と一覧.md の 4 節)。並びと状態は {@see WorkflowProxies::orderedByStatus()}。
 *
 * @phpstan-import-type LinkedPerson from WorkflowPeople
 * @phpstan-import-type Links from PersonLinks
 * @phpstan-import-type ProxyRow from ProxyRows
 * @phpstan-import-type AdminProxyRow from ProxyRows
 *
 * @phpstan-type Flow array{code: string, name: string}
 * @phpstan-type AdminQuery array{principal: LinkedPerson|null, proxy: LinkedPerson|null, kind: string|null,
 *     active: bool}
 */
class ProxyList
{
    /** 代理の管理の 1 ページの件数 */
    public const PER_PAGE = 20;

    /** 人のクエリの値の形(1 から始まる 10 進の id) */
    private const USER_ID_PATTERN = '/^[1-9][0-9]{0,17}$/D';

    public function __construct(
        private WorkflowProxies $proxies,
        private ProxyRows $rows,
        private MasterNames $names,
        private PersonLinks $links,
    ) {}

    /**
     * 代理の設定のページ Workflow/Proxies の props(outgoing = 代理元が自分、incoming = 代理先が自分。today は 2 つのページで同じ)。
     *
     * @return array{outgoing: list<ProxyRow>, incoming: list<ProxyRow>, flows: list<Flow>, today: string,
     *     links: Closure(): Links}
     */
    public function own(AccessContext $context): array
    {
        $userId = $context->userId;
        $commonProps = [
            'flows' => $this->flows(),
            'today' => self::today($context),
            'links' => $this->links->lazy($context),
        ];

        if ($userId === null) {
            return ['outgoing' => [], 'incoming' => [], ...$commonProps];
        }

        $outgoing = $this->ordered(WorkflowProxy::query()->where('principal_user_id', $userId), $context)->get();
        $incoming = $this->ordered(WorkflowProxy::query()->where('proxy_user_id', $userId), $context)->get();

        return [
            'outgoing' => $this->rows->own($outgoing, 'proxy_user_id', $context),
            'incoming' => $this->rows->own($incoming, 'principal_user_id', $context),
            ...$commonProps,
        ];
    }

    /**
     * 代理の管理のページ Workflow/Admin/Proxies の props(クエリ principal・proxy・kind・active・page。解釈できない値は無いのと同じ)。
     *
     * @return array{query: AdminQuery, flows: list<Flow>, today: string, links: Closure(): Links,
     *     proxies: array{data: list<AdminProxyRow>, currentPage: int, lastPage: int, total: int}}
     */
    public function admin(
        AccessContext $context,
        mixed $principal,
        mixed $proxy,
        mixed $kind,
        mixed $active,
        mixed $page,
    ): array {
        $query = [
            'principal' => $this->personOf($principal, $context),
            'proxy' => $this->personOf($proxy, $context),
            'kind' => is_string($kind) ? WorkflowProxyKind::tryFrom($kind)?->value : null,
            'active' => $active === '1',
        ];
        $proxies = $this->filtered($query, $context);
        $total = $proxies->toBase()->getCountForPagination();
        $pageNumber = PageNumbers::of($page, $total, self::PER_PAGE);
        $paginator = $this->ordered($proxies, $context)
            ->paginate(perPage: self::PER_PAGE, page: $pageNumber, total: $total);

        return [
            'query' => $query,
            'flows' => $this->flows(),
            'today' => self::today($context),
            'links' => $this->links->lazy($context),
            'proxies' => [
                'data' => $this->rows->admin($paginator->getCollection(), $context),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * 代理の管理の絞り込み(代理元・代理先・種類・今日に有効な行だけ)。
     *
     * @param  AdminQuery  $query
     * @return Builder<WorkflowProxy>
     */
    private function filtered(array $query, AccessContext $context): Builder
    {
        $proxies = WorkflowProxy::query();

        if ($query['principal'] !== null) {
            $proxies->where('principal_user_id', $query['principal']['id']);
        }

        if ($query['proxy'] !== null) {
            $proxies->where('proxy_user_id', $query['proxy']['id']);
        }

        if ($query['kind'] !== null) {
            $proxies->where('kind', $query['kind']);
        }

        return $query['active'] ? $this->proxies->effective($proxies, $context->date) : $proxies;
    }

    /**
     * @param  Builder<WorkflowProxy>  $proxies
     * @return Builder<WorkflowProxy>
     */
    private function ordered(Builder $proxies, AccessContext $context): Builder
    {
        return $this->proxies->orderedByStatus($proxies, $context->date)->with('workflowFlow');
    }

    /**
     * 代理を追加するダイアログの「対象」の選択肢 = 全部のフロー(登録の規則の「対象のフローが存在する」と同じ範囲。並びはフローの一覧と同じ)。
     *
     * @return list<Flow>
     */
    private function flows(): array
    {
        return array_values(WorkflowFlow::query()->orderBy('sort_key')->orderBy('code')->get(['code', 'name'])
            ->map(fn (WorkflowFlow $flow) => ['code' => $flow->code, 'name' => $flow->name])->all());
    }

    /**
     * 今日(判定の入力の日 = サーバーの今日。追加のダイアログの開始日の既定値に使う。YYYY-MM-DD)。
     */
    private static function today(AccessContext $context): string
    {
        return $context->date->toDateString();
    }

    /**
     * クエリの人(id の形でない・無いユーザーは null)。絞り込みの欄の札に出すので主所属の組織の id を持つ。
     *
     * @return LinkedPerson|null
     */
    private function personOf(mixed $value, AccessContext $context): ?array
    {
        if (! is_string($value) || preg_match(self::USER_ID_PATTERN, $value) !== 1) {
            return null;
        }

        $userId = (int) $value;
        $named = $this->names->users([$userId], $context->date, $context->localeCode)[$userId] ?? null;

        if ($named === null) {
            return null;
        }

        $department = $this->names->mainDepartments([$userId], $context->date, $context->localeCode)[$userId] ?? null;

        return [
            'id' => $userId,
            'code' => $named['code'],
            'name' => $named['name'],
            'mainDepartmentId' => $department['id'] ?? null,
        ];
    }
}
