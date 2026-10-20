<?php

namespace App\Models\Records;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use stdClass;

/**
 * 更新履歴の版の定義の写し labels のキャスト(形 = docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1.2 節)。
 *
 * 読むと PHP の配列(JSON のオブジェクト {} も [] になる)。書くときは写像の位置(fields と明細の項目の入れ子の fields、options と項目ごとの
 * 選択肢)をいつも JSON のオブジェクトにする。読んだ写しをそのまま渡しても形が崩れない。
 *
 * @implements CastsAttributes<array<string, mixed>, array<array-key, mixed>>
 */
class RevisionLabels implements CastsAttributes
{
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

        return json_encode((object) [
            ...$value,
            'fields' => self::fields($value['fields'] ?? []),
            'options' => (object) array_map(
                fn (mixed $options) => (object) (array) $options,
                (array) ($value['options'] ?? []),
            ),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * 項目の id → 項目の写し(明細の項目は入れ子の fields も同じ形)。
     */
    private static function fields(mixed $fields): stdClass
    {
        return (object) array_map(function (mixed $field) {
            $field = (array) $field;

            if (array_key_exists('fields', $field)) {
                $field['fields'] = self::fields($field['fields']);
            }

            return (object) $field;
        }, (array) $fields);
    }
}
