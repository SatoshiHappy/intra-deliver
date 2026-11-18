<?php

namespace App\Queries\Notice;

use App\Models\Notice\Notice;
use App\Support\AccessContext;
use App\Support\PageNumbers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 自分宛の通知の読み取り(docs の 設計/ワークフロー/案件の運用/通知.md の 6 節と 7 節、形は 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.5):
 * 未読の数・鈴の 10 件・通知の一覧・押した時の移り先。判定を求める者にユーザーがいなければ(ゲスト)自分宛の行は無い。
 * 並びは作った日時の新しい順、同じ時刻は id の降順(通知.md の 2 節)。鈴の 10 件だけは未読を先に並べる(7.5。ミュートで既読で作った行が
 * 未読の行を鈴から押し出さないように)。
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
     * 鈴の中の 10 件(未読を先に、続けて既読。未読どうし・既読どうしは一覧と同じ並び)。
     *
     * @return list<NoticeRow>
     */
    public function latest(AccessContext $context): array
    {
        // 未読と既読を分けて引く(既読は (user_id, created_at, id) の索引の並びで上から取れる。1 つの問い合わせで未読を先に並べると、
        // 本人の全行を並べ替える)。2 つの間に既読になった行を 2 度出さないよう、既読の側から未読の側で取った行を除く
        $unread = $this->ordered($this->own($context)->whereNull('read_at'))->limit(self::LATEST_COUNT)->get();
        $rest = self::LATEST_COUNT - $unread->count();
        $read = $rest === 0
            ? new Collection
            : $this->ordered($this->own($context)->whereNotNull('read_at')->whereKeyNot($unread->modelKeys()))
                ->limit($rest)->get();

        return $this->rows->rows($unread->concat($read), $context);
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

        $total = $query->toBase()->getCountForPagination();
        $pageNumber = PageNumbers::of($page, $total, self::PER_PAGE);
        $paginator = $this->ordered($query)->paginate(perPage: self::PER_PAGE, page: $pageNumber, total: $total);

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
}
