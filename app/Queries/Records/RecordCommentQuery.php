<?php

namespace App\Queries\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordCommentMention;
use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * サイドパネルのコメントの欄の読み取り(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 7 節・詳細の画面/README.md の 6.1 節)。
 * 新しい順(id の降順)。削除の印の行は本文・書いた人・宛先を持たず、削除した人と日時を持つ。判定しない(呼び手が閲覧を判定済み)。
 *
 * @phpstan-import-type ReferenceValue from RecordValueReader
 *
 * @phpstan-type CommentRow array{id: int, author: ReferenceValue|null, body: string|null, createdAt: string|null,
 *     mentions: list<ReferenceValue>, deleted: array{by: ReferenceValue|null, at: string|null}|null, mine: bool}
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
        $moduleId = $this->records->topLevel($recordId)->record_module_id;

        return RecordComment::query()->where('record_module_id', $moduleId)->where('record_id', $recordId)->count();
    }

    /**
     * @param  int|null  $beforeId  続きを読むとき、今の一覧の最後のコメントの id(それより古いものを返す)
     * @return CommentsContent
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function content(AccessContext $context, int $recordId, int $limit, ?int $beforeId): array
    {
        $moduleId = $this->records->topLevel($recordId)->record_module_id;
        $comments = RecordComment::query()
            ->with(['recordCommentMentions' => fn (Relation $mentions) => $mentions->orderBy('id')])
            ->where('record_module_id', $moduleId)->where('record_id', $recordId)
            ->when($beforeId !== null, fn ($query) => $query->where('id', '<', $beforeId))
            ->orderByDesc('id')->limit($limit + 1)->get();
        $page = $comments->take($limit);
        $users = $this->reader->users($this->userIds(array_values($page->all())), $context->date, $context->localeCode);

        return [
            'kind' => 'comments',
            'rows' => array_values($page->map(
                fn (RecordComment $comment) => $this->row($comment, $users, $context->userId),
            )->all()),
            'hasMore' => $comments->count() > $limit,
            'canPost' => $context->userId !== null,
            'canDeleteOthers' => $this->administration->allows(Requester::fromAccessContext($context), $context->date),
        ];
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
     * @return CommentRow
     */
    private function row(RecordComment $comment, array $users, ?int $currentUserId): array
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
            'mine' => ! $deleted && $currentUserId !== null && $comment->user_id === $currentUserId,
        ];
    }
}
