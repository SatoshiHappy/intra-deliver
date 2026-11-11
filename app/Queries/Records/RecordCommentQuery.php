<?php

namespace App\Queries\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordCommentMention;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordActivityQuery;
use App\Support\AccessContext;
use App\Support\PageNumbers;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * コメントの一覧の読み取り(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 7 節)。サイドパネルの欄と公開の口が
 * 同じ行の中身になるよう、行を 1 か所で作る。判定しない(呼び手が閲覧を判定済み)。
 *
 * @phpstan-import-type ReferenceValue from RecordValueReader
 *
 * @phpstan-type CommentEntry array{id: int, author: ReferenceValue|null, body: string|null, createdAt: string|null,
 *     mentions: list<ReferenceValue>, deleted: array{by: ReferenceValue|null, at: string|null}|null}
 * @phpstan-type CommentRow array{id: int, author: ReferenceValue|null, body: string|null, createdAt: string|null,
 *     mentions: list<ReferenceValue>, deleted: array{by: ReferenceValue|null, at: string|null}|null, mine: bool}
 * @phpstan-type CommentPage array{rows: list<CommentEntry>, total: int, currentPage: int, lastPage: int}
 * @phpstan-type CommentsContent array{kind: 'comments', rows: list<CommentRow>, hasMore: bool, canPost: bool,
 *     canDeleteOthers: bool}
 */
class RecordCommentQuery
{
    public function __construct(
        private RecordQuery $records,
        private RecordValueReader $reader,
        private RecordCommentAdministration $administration,
    ) {}

    /**
     * 帯の件数(削除の印の行も数える)。
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function countFor(int $recordId): int
    {
        return $this->commentsOf($recordId)->count();
    }

    /**
     * @param  int|null  $beforeId  続きを読むとき、今の一覧の最後のコメントの id(それより古いものを返す)
     * @return CommentsContent
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function content(AccessContext $context, int $recordId, int $limit, ?int $beforeId): array
    {
        $comments = $this->withMentions($this->commentsOf($recordId))
            ->when($beforeId !== null, fn ($query) => $query->where('id', '<', $beforeId))
            ->orderByDesc('id')->limit($limit + 1)->get();
        $page = array_values($comments->take($limit)->all());
        $rows = [];

        foreach ($this->entries($page, $context->date, $context->localeCode) as $index => $entry) {
            $rows[] = [...$entry, 'mine' => $this->isMine($page[$index], $context->userId)];
        }

        return [
            'kind' => 'comments',
            'rows' => $rows,
            'hasMore' => $comments->count() > $limit,
            'canPost' => $context->userId !== null,
            'canDeleteOthers' => $this->administration->allows(Requester::fromAccessContext($context), $context->date),
        ];
    }

    /**
     * ページの番号で読む(公開の口 {@see RecordActivityQuery::comments()} の中身。保証 = docs の
     * 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3)。
     *
     * @param  int<1, max>  $perPage
     * @return CommentPage
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function pageOf(int $recordId, int $page, int $perPage, DateTimeInterface $date, string $localeCode): array
    {
        $query = $this->commentsOf($recordId);
        $total = $query->toBase()->getCountForPagination();
        $paginator = $this->withMentions($query)->orderByDesc('id')
            ->paginate(perPage: $perPage, page: PageNumbers::clamp($page, $total, $perPage), total: $total);

        return [
            'rows' => $this->entries(array_values($paginator->items()), $date, $localeCode),
            'total' => $paginator->total(),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
        ];
    }

    /**
     * レコード(親の無いもの)のコメント(削除の印の行を含む)。
     *
     * @return Builder<RecordComment>
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    private function commentsOf(int $recordId): Builder
    {
        $moduleId = $this->records->topLevel($recordId)->record_module_id;

        return RecordComment::query()->where('record_module_id', $moduleId)->where('record_id', $recordId);
    }

    /**
     * @param  Builder<RecordComment>  $query
     * @return Builder<RecordComment>
     */
    private function withMentions(Builder $query): Builder
    {
        return $query->with(['recordCommentMentions' => fn (Relation $mentions) => $mentions->orderBy('id')]);
    }

    /**
     * @param  list<RecordComment>  $comments
     * @return list<CommentEntry>
     */
    private function entries(array $comments, DateTimeInterface $date, string $localeCode): array
    {
        $users = $this->reader->users($this->userIds($comments), $date, $localeCode);

        return array_map(fn (RecordComment $comment) => $this->entry($comment, $users), $comments);
    }

    /**
     * 行に出す人(書いた人・削除した人・宛先)のユーザー。
     *
     * @param  list<RecordComment>  $comments
     * @return list<int>
     */
    private function userIds(array $comments): array
    {
        $ids = [];

        foreach ($comments as $comment) {
            $ids[] = $comment->deleted_by_user_id ?? $comment->user_id;

            foreach ($comment->recordCommentMentions as $mention) {
                $ids[] = $mention->user_id;
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @param  array<int, ReferenceValue>  $users
     * @return CommentEntry
     */
    private function entry(RecordComment $comment, array $users): array
    {
        $deleted = $comment->deleted_at !== null;

        return [
            'id' => $comment->id,
            'author' => $deleted ? null : $users[$comment->user_id] ?? null,
            'body' => $comment->body,
            'createdAt' => $comment->created_at?->toIso8601String(),
            'mentions' => $deleted ? [] : array_values($comment->recordCommentMentions
                ->map(fn (RecordCommentMention $mention) => $users[$mention->user_id] ?? null)
                ->filter()->all()),
            'deleted' => $deleted ? [
                'by' => $comment->deleted_by_user_id === null ? null : $users[$comment->deleted_by_user_id] ?? null,
                'at' => $comment->deleted_at->toIso8601String(),
            ] : null,
        ];
    }

    private function isMine(RecordComment $comment, ?int $currentUserId): bool
    {
        return $comment->deleted_at === null && $currentUserId !== null && $comment->user_id === $currentUserId;
    }
}
