<?php

namespace App\Queries\Notice;

use App\Models\Notice\Notice;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 自分宛の通知の読み取り(docs の 設計/ワークフロー/案件の運用/通知.md の 6 節と 7 節、形は 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.5):
 * 未読の数・鈴の最新 10 件・通知の一覧・押した時の移り先。判定を求める者にユーザーがいなければ(ゲスト)自分宛の行は無い。
 * 並びは作った日時の新しい順、同じ時刻は id の降順(通知.md の 2 節)。
 *
 * @phpstan-import-type NoticeRow from NoticeRows
 */
class NoticeList
{
    /** 一覧の 1 ページの件数 */
    public const PER_PAGE = 20;

    /** 鈴の中の件数 */
    public const LATEST_COUNT = 10;

    private const FILTERS = ['all', 'unread'];

    private const DEFAULT_FILTER = 'all';

    public function __construct(private NoticeRows $rows) {}

    /**
     * どの画面にも渡す未読の数。ゲストは null(鈴を出さない)。
     *
     * @return array{unreadCount: int}|null
     */
    public function summary(AccessContext $context): ?array
    {
        return $context->userId === null ? null : ['unreadCount' => $this->unreadCount($context)];
    }

    /**
     * 鈴の中の最新 10 件(既読・未読の両方)。
     *
     * @return list<NoticeRow>
     */
    public function latest(AccessContext $context): array
    {
        return $this->rows->rows($this->ordered($this->own($context))->limit(self::LATEST_COUNT)->get(), $context);
    }

    /**
     * 通知の一覧のページ(20 件ずつ)。
     *
     * @param  mixed  $filter  クエリの filter(all・unread。ほかは all)
     * @param  mixed  $page  クエリの page
     * @return array{query: array{filter: string}, counts: array{unread: int},
     *     notices: array{data: list<NoticeRow>, currentPage: int, lastPage: int, total: int}}
     */
    public function page(AccessContext $context, mixed $filter, mixed $page): array
    {
        $filter = is_string($filter) && in_array($filter, self::FILTERS, true) ? $filter : self::DEFAULT_FILTER;
        $query = $this->own($context);

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        }

        $paginator = $this->ordered($query)->paginate(perPage: self::PER_PAGE, page: $this->pageOf($page));

        return [
            'query' => ['filter' => $filter],
            'counts' => ['unread' => $this->unreadCount($context)],
            'notices' => [
                'data' => $this->rows->rows(new Collection($paginator->items()), $context),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * 押した時の移り先(既読にした後にもう一度判定する = 通知.md の 5 節)。url が null で unavailableText があれば、対象が見えない。
     *
     * @return array{url: string|null, unavailableText: string|null}
     *
     * @throws ModelNotFoundException<Notice> 行が無い・宛先が自分でないとき
     */
    public function destination(AccessContext $context, int $noticeId): array
    {
        $notice = $this->own($context)->whereKey($noticeId)->firstOrFail();
        $row = $this->rows->rows(new Collection([$notice]), $context)[0];

        return ['url' => $row['url'], 'unavailableText' => $row['unavailableText']];
    }

    private function unreadCount(AccessContext $context): int
    {
        return $this->own($context)->whereNull('read_at')->count();
    }

    /**
     * 判定を求める者のユーザー宛の行(ゲストは 0 行)。
     *
     * @return Builder<Notice>
     */
    private function own(AccessContext $context): Builder
    {
        $query = Notice::query();

        return $context->userId === null ? $query->whereRaw('false') : $query->where('user_id', $context->userId);
    }

    /**
     * @param  Builder<Notice>  $query
     * @return Builder<Notice>
     */
    private function ordered(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * クエリの page(1 から始まる 10 進の整数の文字列だけを読む。案件一覧と同じ規則)。
     */
    private function pageOf(mixed $page): int
    {
        return is_string($page) && preg_match('/^[1-9][0-9]{0,8}$/D', $page) === 1 ? (int) $page : 1;
    }
}
