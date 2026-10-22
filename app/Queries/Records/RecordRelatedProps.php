<?php

namespace App\Queries\Records;

use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRelatedEntry;
use App\Queries\Records\Api\RecordRelatedQuery;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * レコードの詳細の関連レコードの props(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 3 節・6.1 節、
 * 関連レコード.md の 3 節・7 節)。クエリ tab・related・relatedPage はここが解釈し、解釈できない値は既定(先頭の入口・1 ページ目)にする。
 * 読み取りは公開の口 {@see RecordRelatedQuery}。判定(read と行の判定)は入口が済ませてから呼ぶ。
 *
 * @phpstan-import-type RelatedRows from RecordRelatedQuery
 * @phpstan-import-type RelatedForm from RecordRelatedQuery
 */
class RecordRelatedProps
{
    public function __construct(private RecordRelatedQuery $related) {}

    /**
     * relatedRecords: 見える入口と件数(見える入口が無ければ null)。選んだ入口は画面が URL のクエリ related から決める。
     *
     * @return array{entries: list<array{key: string, name: string, count: int, canCreate: bool}>}|null
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function records(AccessContext $context, int $recordId): ?array
    {
        $entries = $this->related->entries(Requester::fromAccessContext($context), $recordId, $context->date);

        return $entries === []
            ? null
            : ['entries' => array_map(fn (RecordRelatedEntry $entry) => $entry->toArray(), $entries)];
    }

    /**
     * relatedRecordRows: クエリ tab が related のときだけ、選んだ入口(無い・見えなければ先頭)の表。見える入口が無ければ null。
     * 入口を決めるのに件数を数えない(entryNames)。$page は部分の再読み込みのクエリ relatedPage(正の整数でなければ 1 ページ目)。
     *
     * @return RelatedRows|null
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function rows(AccessContext $context, int $recordId, mixed $tab, mixed $related, mixed $page): ?array
    {
        if ($tab !== 'related') {
            return null;
        }

        $requester = Requester::fromAccessContext($context);
        $keys = array_column($this->related->entryNames($requester, $recordId, $context->date), 'key');
        $key = is_string($related) && in_array($related, $keys, true) ? $related : ($keys[0] ?? null);
        $number = is_string($page) && preg_match('/^[1-9][0-9]{0,8}\z/', $page) === 1 ? (int) $page : 1;

        return is_string($key)
            ? $this->related->rows($requester, $recordId, $key, $number, $context->date, $context->localeCode)
            : null;
    }

    /**
     * relatedRecordForm: クエリ related の入口の「新規」のダイアログのフォーム(出せなければ null)。
     *
     * @return RelatedForm|null
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function form(AccessContext $context, int $recordId, mixed $related): ?array
    {
        if (! is_string($related)) {
            return null;
        }

        $requester = Requester::fromAccessContext($context);

        return $this->related->form($requester, $recordId, $related, $context->date, $context->localeCode);
    }
}
