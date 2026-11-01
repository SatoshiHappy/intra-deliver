<?php

namespace App\Queries\Workflow;

use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRelatedEntry;
use App\Queries\Records\Api\RecordRelatedQuery;
use App\Support\PageNumbers;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 申請の詳細の関連レコードの props。読み取りはレコードの詳細と同じ公開の口 {@see RecordRelatedQuery} を使う(クエリの解釈をそろえるため。
 * docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 5 節・7 節、
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 6 節)。
 *
 * @phpstan-import-type RelatedRows from RecordRelatedQuery
 * @phpstan-import-type RelatedForm from RecordRelatedQuery
 */
class CaseRelatedRecords
{
    /** 関連レコードのタブ(クエリ tab の値と名前) */
    public const TAB = ['key' => 'related', 'name' => '関連レコード'];

    public function __construct(private RecordRelatedQuery $related) {}

    /**
     * relatedRecords: 見える入口と件数(見える入口が無ければ null)。
     *
     * @return array{entries: list<array{key: string, name: string, count: int, canCreate: bool}>}|null
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function records(Requester $requester, int $recordId, DateTimeInterface $date): ?array
    {
        $entries = $this->related->entries($requester, $recordId, $date);

        return $entries === []
            ? null
            : ['entries' => array_map(fn (RecordRelatedEntry $entry) => $entry->toArray(), $entries)];
    }

    /**
     * relatedRecordRows: クエリ tab が related のときだけ、選んだ入口(無い・見えなければ先頭)の表。見える入口が無ければ null。
     * ページは部分の再読み込みのときだけ読む(全体の応答は 1 ページ目)。
     *
     * @return RelatedRows|null
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function rows(
        Requester $requester,
        int $recordId,
        CaseShowSelection $selection,
        DateTimeInterface $date,
        string $localeCode,
    ): ?array {
        if ($selection->tab !== self::TAB['key']) {
            return null;
        }

        $keys = array_column($this->related->entryNames($requester, $recordId, $date), 'key');
        $number = PageNumbers::requested($selection->partial ? $selection->relatedPage : null);
        $related = $selection->related;
        $key = is_string($related) && in_array($related, $keys, true) ? $related : ($keys[0] ?? null);

        return is_string($key)
            ? $this->related->rows($requester, $recordId, $key, $number, $date, $localeCode)
            : null;
    }

    /**
     * relatedRecordForm: クエリ related の入口の「新規」のダイアログのフォーム(出せなければ null)。
     *
     * @return RelatedForm|null
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function form(
        Requester $requester,
        int $recordId,
        CaseShowSelection $selection,
        DateTimeInterface $date,
        string $localeCode,
    ): ?array {
        $related = $selection->related;

        return is_string($related)
            ? $this->related->form($requester, $recordId, $related, $date, $localeCode)
            : null;
    }

    /**
     * maskedRelatedTab: 申請書のレコードを閲覧できない人の関連レコードのタブ(見える入口が無ければ null。件数を持たない =
     * 入口の名前だけを読む口を呼ぶ)。
     *
     * @return array{key: string, name: string}|null
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function maskedTab(Requester $requester, int $recordId, DateTimeInterface $date): ?array
    {
        return $this->related->entryNames($requester, $recordId, $date) === [] ? null : self::TAB;
    }
}
