<?php

namespace App\Queries\Records;

/**
 * 更新履歴の欄の行に、契機の持ち主の画面へのリンクを足す(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5.1。欄の経路だけで、
 * AI の読む道具の公開の口の行には足さない)。判定しない(リンクは版を見られる人の全員に出し、開いた先の画面が判定する)。
 *
 * @phpstan-import-type RevisionRow from RecordRevisionRows
 */
class RecordRevisionHoldLinks
{
    public function __construct(private RecordHoldNoticeCatalog $notices) {}

    /**
     * 契機のある行の origin に link(リンクか null)を足す。案内の口は 1 ページに 1 回だけ呼ぶ。
     *
     * @param  list<RevisionRow>  $rows
     * @return list<RevisionRow>
     */
    public function withLinks(int $recordId, array $rows): array
    {
        $originIds = array_values(array_map(
            fn (array $row) => $row['id'],
            array_filter($rows, fn (array $row) => $row['origin'] !== null),
        ));
        $links = $this->notices->revisionLinks($recordId, $originIds);

        return array_map(function (array $row) use ($links) {
            if ($row['origin'] !== null) {
                $link = $links[$row['id']] ?? null;
                $row['origin']['link'] = $link === null ? null : RecordHoldNoticeCatalog::linkProps($link);
            }

            return $row;
        }, $rows);
    }
}
