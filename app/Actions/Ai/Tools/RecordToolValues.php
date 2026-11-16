<?php

namespace App\Actions\Ai\Tools;

/**
 * 探す・読むが結果に入れる値の形(docs の 設計/AI/道具と権限/README.md 10.2 の「返す値の形は、ふだんの画面と同じ規則」)。表示の名前は
 * 画面のレコードの見出しと同じく表示の項目の値で、無ければ #<id>。名前の無い参照の相手は、画面と同じくコードか #<id> で示す(見えない
 * 参照先を含む)。値の中の文字列は直さない(包みが結果の文にする時に印の部品で直す)。
 */
final class RecordToolValues
{
    /**
     * 並べられる型(参照と明細のほかの型 = 入れ物の型の isPlain。道具の名前空間は入れ物の列挙に依存しないので値で持ち、列挙と同じことを
     * テストで確かめる)。
     */
    public const SORTABLE_TYPES = ['string', 'integer', 'decimal', 'date', 'datetime', 'boolean', 'choice'];

    /** 範囲で探す型(大小を比べられる型 = 入れ物の型の isOrdered。同じくテストで確かめる) */
    public const ORDERED_TYPES = ['integer', 'decimal', 'date', 'datetime'];

    /** 参照の相手の値の鍵(並べた物。ユーザーと組織の相手は加えて type) */
    private const REFERENCE_KEYS = [
        ['affiliation', 'code', 'id', 'label'],
        ['affiliation', 'code', 'id', 'label', 'type'],
    ];

    /**
     * 業務モジュールの定義の表示の項目のコード(無ければ null)。
     *
     * @param  array<array-key, mixed>  $definition  業務モジュールの定義(項目は fields)
     */
    public static function titleCode(array $definition): ?string
    {
        foreach (self::fields($definition) as $field) {
            if (($field['isTitle'] ?? false) === true && is_string($field['code'] ?? null)) {
                return $field['code'];
            }
        }

        return null;
    }

    /**
     * 定義の項目のコード → 型(コードか型が文字列でない項目は除く)。
     *
     * @param  array<array-key, mixed>  $definition
     * @return array<string, string>
     */
    public static function types(array $definition): array
    {
        $types = [];

        foreach (self::fields($definition) as $field) {
            if (is_string($field['code'] ?? null) && is_string($field['type'] ?? null)) {
                $types[$field['code']] = $field['type'];
            }
        }

        return $types;
    }

    /**
     * 定義の項目(定義の並び。配列でない物は除く)。
     *
     * @param  array<array-key, mixed>  $definition
     * @return list<array<array-key, mixed>>
     */
    public static function fields(array $definition): array
    {
        $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];

        return array_values(array_filter($fields, is_array(...)));
    }

    /**
     * レコードの表示の名前(表示の項目の値。無ければ #<id>)。
     *
     * @param  array<array-key, mixed>  $values  項目のコード → 値
     */
    public static function displayName(array $values, ?string $titleCode, int $id): string
    {
        $title = $titleCode === null ? null : ($values[$titleCode] ?? null);

        return is_string($title) && $title !== '' ? $title : "#{$id}";
    }

    /**
     * 値の中の参照の相手のうち、名前(label)の無い物に、画面と同じ示し方(コード、無ければ #<id>)を入れる(入れ子の中まで)。
     */
    public static function shown(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (self::isReference($value)) {
            $value['label'] ??= is_string($value['code']) ? $value['code'] : '#'.$value['id'];

            return $value;
        }

        return array_map(self::shown(...), $value);
    }

    /**
     * 参照の相手の値の形か。
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function isReference(array $value): bool
    {
        $keys = array_keys($value);
        sort($keys);

        return is_int($value['id'] ?? null) && in_array($keys, self::REFERENCE_KEYS, true);
    }
}
