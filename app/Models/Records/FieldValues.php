<?php

namespace App\Models\Records;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * レコードの通常の項目の値 field_values のキャスト(docs の 設計/業務データの入れ物/データモデル/値の形と直列化.md 3 節)。
 *
 * 読むと、項目の id → 値の配列(PHP の配列なので、10 進の文字列のキーは整数のキーになる)。書くときは、空の配列も JSON のオブジェクト {} にする
 * (Laravel の array のキャストは空の配列を [] にし、DB の検査制約 records_field_values_check に拒まれるため)。
 *
 * @implements CastsAttributes<array<int, mixed>, array<int|string, mixed>>
 */
class FieldValues implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<int, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        /** @var array<int, mixed> */
        return json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<int|string, mixed>|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode((object) $value, JSON_THROW_ON_ERROR);
    }
}
