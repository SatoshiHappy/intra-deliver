<?php

namespace App\Actions\Workflow;

use stdClass;

/**
 * jsonb に書く写像の位置を JSON のオブジェクトにする 1 か所(空の配列も {} になる。PHP の [] は json_encode で [] になるため)。
 * 対象 = 一時保存の入力 draft_input(docs の 設計/ワークフロー/業務データとの接続/README.md 2 節)の values・details と明細の行の values。
 * 明細の項目の行の並びは配列のまま(0 行でも [])。DB の検査制約(jsonb_typeof = 'object')は最上位しか見ないので、中の形はここで決める。
 */
final class JsonObjectMaps
{
    /**
     * 一時保存の入力。
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $details
     * @return array{name: string|null, comment: string|null, values: stdClass, details: stdClass, base_date: string|null}
     */
    public static function draftInput(?string $name, ?string $comment, array $values, array $details, ?string $baseDate): array
    {
        return [
            'name' => $name,
            'comment' => $comment,
            'values' => (object) $values,
            'details' => self::details($details),
            'base_date' => $baseDate,
        ];
    }

    /**
     * 明細の項目 → 行の並び。行の values が配列のときだけオブジェクトにする(配列でない行・値は送られたまま残す)。
     *
     * @param  array<array-key, mixed>  $details
     */
    private static function details(array $details): stdClass
    {
        $converted = [];

        foreach ($details as $code => $rows) {
            $row = fn (mixed $row) => is_array($row) ? self::row($row) : $row;
            $converted[$code] = is_array($rows) ? array_map($row, $rows) : $rows;
        }

        return (object) $converted;
    }

    /**
     * @param  array<array-key, mixed>  $row
     * @return array<array-key, mixed>
     */
    private static function row(array $row): array
    {
        if (is_array($row['values'] ?? null)) {
            $row['values'] = (object) $row['values'];
        }

        return $row;
    }
}
