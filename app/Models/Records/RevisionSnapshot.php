<?php

namespace App\Models\Records;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * 更新履歴の版の写し snapshot のキャスト(形 = docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1.1 節)。
 *
 * 読むと PHP の配列(JSON のオブジェクト {} も [] になる)。書くときは写像の位置(values・references・details と明細の行の values・references)を
 * いつも JSON のオブジェクトにし、並び(明細の項目の行・attachments)は配列のままにする。読んだ写しをそのまま渡しても形が崩れない。
 *
 * @implements CastsAttributes<array<string, mixed>, array<array-key, mixed>>
 */
class RevisionSnapshot implements CastsAttributes
{
    /** 写像の位置(最上位と明細の行) */
    private const ROW_MAPS = ['values', 'references'];

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        /** @var array<string, mixed> */
        return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $snapshot = self::withMaps($value, self::ROW_MAPS);
        $row = fn (mixed $row) => (object) self::withMaps((array) $row, self::ROW_MAPS);
        $snapshot['details'] = (object) array_map(
            fn (mixed $rows) => array_map($row, array_values((array) $rows)),
            (array) ($value['details'] ?? []),
        );
        $snapshot['attachments'] = array_values((array) ($value['attachments'] ?? []));

        return json_encode((object) $snapshot, JSON_THROW_ON_ERROR);
    }

    /**
     * 写像の位置 $keys を JSON のオブジェクトにする(無ければ空のオブジェクト)。
     *
     * @param  array<array-key, mixed>  $value
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private static function withMaps(array $value, array $keys): array
    {
        foreach ($keys as $mapKey) {
            $value[$mapKey] = self::map($value[$mapKey] ?? []);
        }

        return $value;
    }

    private static function map(mixed $value): stdClass
    {
        return (object) (array) $value;
    }
}
