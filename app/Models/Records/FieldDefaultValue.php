<?php

namespace App\Models\Records;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\ComparesCastableAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/**
 * 項目の既定値 default_value(jsonb)のキャスト(docs の 設計/業務データの入れ物/データモデル/表.md 2.2、値の形と直列化.md 3 節)。
 * 読むと文字列・整数・真偽か、相対の既定値のオブジェクトを {@see RelativeDefault} にしたもの(列が NULL なら null)。
 * それ以外の値は黙って null にせず例外にする(読んだ値を null と見て次の更新で書き戻すのを防ぐ。DB の検査制約も種類を止める)。
 * 変わったかは JSON の値で比べる(jsonb が返す文字の空白と、書き戻す文字の違いを変更とみなさない)。配列にするときは保存形と同じ形。
 *
 * @implements CastsAttributes<string|int|bool|RelativeDefault|null, string|int|bool|RelativeDefault|null>
 */
class FieldDefaultValue implements CastsAttributes, ComparesCastableAttributes, SerializesCastableAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws UnexpectedValueException 既定値の形でない値のとき
     */
    public function get(
        Model $model,
        string $key,
        mixed $value,
        array $attributes,
    ): string|int|bool|RelativeDefault|null {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $this->relative($decoded, $key) : $this->scalar($decoded, $key);
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws UnexpectedValueException 既定値の形でない値のとき
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return match (true) {
            $value === null => null,
            $value instanceof RelativeDefault => json_encode($value->toObject(), JSON_THROW_ON_ERROR),
            default => json_encode($this->scalar($value, $key), JSON_THROW_ON_ERROR),
        };
    }

    /**
     * 保存した文字(DB の値か set の結果)どうしが同じ既定値か。両方が null のときは Eloquent が先に同じとみなす。
     */
    public function compare(Model $model, string $key, mixed $firstValue, mixed $secondValue): bool
    {
        if ($firstValue === null || $secondValue === null) {
            return false;
        }

        return json_decode((string) $firstValue, true, flags: JSON_THROW_ON_ERROR)
            === json_decode((string) $secondValue, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return string|int|bool|array{relative: string}|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): string|int|bool|array|null
    {
        return $value instanceof RelativeDefault ? $value->toObject() : $value;
    }

    /**
     * @param  array<array-key, mixed>  $decoded
     *
     * @throws UnexpectedValueException 長さだけを持つオブジェクトでないとき
     */
    private function relative(array $decoded, string $key): RelativeDefault
    {
        return RelativeDefault::tryFromObject($decoded)
            ?? throw $this->unexpected($key, json_encode($decoded, JSON_THROW_ON_ERROR));
    }

    /**
     * @throws UnexpectedValueException 文字列・整数・真偽でない値のとき
     */
    private function scalar(mixed $value, string $key): string|int|bool
    {
        return is_string($value) || is_int($value) || is_bool($value)
            ? $value
            : throw $this->unexpected($key, get_debug_type($value));
    }

    private function unexpected(string $key, string $given): UnexpectedValueException
    {
        return new UnexpectedValueException(
            "{$key} は文字列・整数・真偽の JSON の値か、登録の時からの期間のオブジェクト"
            .'({"relative": "P<0〜999><D|M|Y>"})だけを持てます('.$given.')',
        );
    }
}
