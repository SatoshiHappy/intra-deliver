<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordCommentQuery;
use App\Queries\Records\RecordQuery;
use App\Queries\Records\RecordRevisionRows;
use App\Support\PageNumbers;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * レコードのコメントの一覧と更新履歴の一覧をページの番号で読む口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の
 * 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の 7.3 の表の RecordActivityQuery の 2 行)。行の中身はサイドパネルの欄と
 * 同じ 1 か所({@see RecordCommentQuery}・{@see RecordRevisionRows})で作る。鍵を取らない。
 *
 * @phpstan-import-type CommentPage from RecordCommentQuery
 * @phpstan-import-type RevisionRow from RecordRevisionRows
 */
class RecordActivityQuery
{
    public function __construct(
        private RecordQuery $records,
        private RecordCommentQuery $comments,
        private RecordRevisionRows $revisions,
    ) {}

    /**
     * 主体の判定をしない。呼び手が閲覧の判定({@see RecordViewAccess})を済ませてから呼ぶ。
     *
     * 新しい順のコメントのページ(削除の印の行を含み 1 件に数える。total は帯の件数と同じ)。今の利用者に依る値と操作の可否は持たない。
     *
     * @param  int  $page  引きたいページ(最後のページに詰める。1 未満は 1)
     * @return CommentPage
     *
     * @throws LogicException $perPage が 1 未満のとき(呼び手の誤り)
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function comments(int $recordId, int $page, int $perPage, DateTimeInterface $date, string $localeCode): array
    {
        PageNumbers::throwIfPerPageBelowOne($perPage);

        return $this->comments->pageOf($recordId, $page, $perPage, $date, $localeCode);
    }

    /**
     * 主体の判定をしない。呼び手が閲覧の判定({@see RecordViewAccess})を済ませてから呼ぶ。
     *
     * 新しい版からの更新履歴のページ(行はサイドパネルの更新履歴の欄と同じ。ファイルを開く URL は持たない)。判定を求める者は、差分の参照先の
     * 表示名の出し分けにだけ使う(見えない参照先は #<id>)。
     *
     * @param  int  $page  引きたいページ(最後のページに詰める。1 未満は 1)
     * @return array{rows: list<RevisionRow>, total: int, currentPage: int, lastPage: int}
     *
     * @throws LogicException $perPage が 1 未満のとき(呼び手の誤り)
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function revisions(
        Requester $requester,
        int $recordId,
        int $page,
        int $perPage,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        PageNumbers::throwIfPerPageBelowOne($perPage);
        $this->records->topLevel($recordId);

        return $this->revisions->numberedPage($requester, $recordId, $page, $perPage, $date, $localeCode);
    }
}
