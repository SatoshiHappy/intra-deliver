<?php

namespace App\Actions\Ai\Tools;

/**
 * 読むの明細の行のページ(docs の 設計/AI/道具と権限/README.md 10.2 の読むの明細の行のページ)。明細の表ごとに、詳細の画面と同じ行の順で、
 * 1 ページ {@see ReadRecord::DETAILS_PER_PAGE} 行まで返し、表ごとの行の総数と最後のページを添える。最後のページより大きい番号のページは、
 * その表の行が空になる(表ごとに行の数が違うので、ページの番号を表ごとに丸めない)。
 */
final class ReadRecordDetails
{
    /**
     * 明細の項目ごとの 1 ページ(コード・名前・行ごとの id と、明細の定義で名前を付けた値・総数・ページ)。
     *
     * @param  list<array<array-key, mixed>>  $fields  定義の項目
     * @param  array<string, list<array{id: int, values: array<string, mixed>}>>  $details  明細の項目のコード → 行
     * @return list<array{code: string, name: mixed, rows: list<array{id: int, values: list<array<string, mixed>>}>,
     *     total: int, currentPage: int, lastPage: int}>
     */
    public static function page(array $fields, array $details, int $page): array
    {
        $named = [];

        foreach ($fields as $field) {
            $code = $field['code'] ?? null;

            if (! is_string($code) || ! isset($details[$code])) {
                continue;
            }

            $child = is_array($field['childModule'] ?? null) ? RecordToolValues::fields($field['childModule']) : [];
            $total = count($details[$code]);
            $rows = array_map(
                fn (array $row): array => ['id' => $row['id'], 'values' => ReadRecord::named($child, $row['values'])],
                array_slice($details[$code], ($page - 1) * ReadRecord::DETAILS_PER_PAGE, ReadRecord::DETAILS_PER_PAGE),
            );
            $named[] = [
                'code' => $code,
                'name' => $field['name'] ?? null,
                'rows' => $rows,
                'total' => $total,
                'currentPage' => $page,
                'lastPage' => max(1, (int) ceil($total / ReadRecord::DETAILS_PER_PAGE)),
            ];
        }

        return $named;
    }
}
