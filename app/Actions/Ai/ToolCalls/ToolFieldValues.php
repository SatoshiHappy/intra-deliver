<?php

namespace App\Actions\Ai\ToolCalls;

/**
 * 書く道具の書く値を、実行の始めの定義で確かめる部品(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B1 の「定義による項目と選択肢」)。
 * 見るのは、無い項目・明細の項目を値に置いた件・型に合わない形・今の選択肢に無いコードだけで、長さ・必須・規則は書く時に入れ物が見る(P7)。
 * 値の形は入れ物の入力の形(参照は id の整数、選択はコード、相手は id の形。複数の値は列。null は空にする)。前の件を指す参照の値
 * {"ref": "…"} は他モジュール参照の項目にだけ置ける(指した件の確かめ = {@see ToolCallLinks})。
 */
final class ToolFieldValues
{
    /** 型ごとの、値 1 つの形の確かめ */
    private const SCALAR_TYPES = ['string', 'integer', 'decimal', 'date', 'datetime', 'boolean'];

    /**
     * 項目の値の誤りの文(項目のコード → 書く値)。
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $definition
     * @return list<string>
     */
    public static function problems(array $values, array $definition, string $place): array
    {
        $fields = self::fields($definition);
        $problems = [];

        foreach ($values as $code => $value) {
            $field = $fields[(string) $code] ?? null;
            $problems[] = match (true) {
                $field === null => "{$place}項目 `{$code}` は無い。",
                ($field['type'] ?? null) === 'details' => "{$place}項目 `{$code}` は明細の項目で、values でなく details に書く。",
                default => self::valueProblem($field, $value, "{$place}項目 `{$code}`"),
            };
        }

        return array_values(array_filter($problems, is_string(...)));
    }

    /**
     * 明細の行の誤りの文(明細の項目のコード → 行の列)。
     *
     * @param  array<array-key, mixed>  $details
     * @param  array<array-key, mixed>  $definition
     * @return list<string>
     */
    public static function detailProblems(array $details, array $definition): array
    {
        $children = ToolPartyFields::children($definition);
        $problems = [];

        foreach ($details as $code => $rows) {
            $child = $children[(string) $code] ?? null;

            if ($child === null) {
                $problems[] = "明細の項目 `{$code}` は無い。";

                continue;
            }

            array_push($problems, ...self::rowProblems((string) $code, is_array($rows) ? $rows : [], $child));
        }

        return $problems;
    }

    /**
     * 項目の定義(コード → 定義)。
     *
     * @param  array<array-key, mixed>  $definition
     * @return array<string, array<array-key, mixed>>
     */
    public static function fields(array $definition): array
    {
        $fields = is_array($definition['fields'] ?? null) ? array_filter($definition['fields'], is_array(...)) : [];
        $byCode = [];

        foreach ($fields as $field) {
            if (is_string($field['code'] ?? null)) {
                $byCode[$field['code']] = $field;
            }
        }

        return $byCode;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @param  array<array-key, mixed>  $child  明細のモジュールの定義
     * @return list<string>
     */
    private static function rowProblems(string $code, array $rows, array $child): array
    {
        $problems = [];

        foreach (array_values($rows) as $index => $row) {
            $place = "明細 `{$code}` の ".($index + 1).' 行目の';
            $values = is_array($row) ? ($row['values'] ?? null) : null;
            $problems = [...$problems, ...(is_array($values)
                ? self::problems($values, $child, $place)
                : ["{$place}行が {\"id\": <行の id>(新しい行は省く), \"values\": {…}} の形でない。"])];
        }

        return $problems;
    }

    /**
     * 値の形の誤り(null は空にする書き込みで通す。複数の値の項目は列、ほかは 1 つ)。
     *
     * @param  array<array-key, mixed>  $field
     */
    private static function valueProblem(array $field, mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        $multiple = ($field['isMultiple'] ?? false) === true;
        $isList = is_array($value) && array_is_list($value);

        if ($multiple !== $isList) {
            return $multiple ? "{$label} は複数の値の項目で、値を列で書く。" : "{$label} は値を 1 つで書く(列にしない)。";
        }

        $type = is_string($field['type'] ?? null) ? $field['type'] : '';
        $problems = array_map(
            fn (mixed $element): ?string => self::elementProblem($field, $type, $element, $label),
            $isList ? $value : [$value],
        );

        return array_values(array_filter($problems, is_string(...)))[0] ?? null;
    }

    /**
     * 値 1 つの形の誤り。
     *
     * @param  array<array-key, mixed>  $field
     */
    private static function elementProblem(array $field, string $type, mixed $element, string $label): ?string
    {
        $fits = match (true) {
            in_array($type, self::SCALAR_TYPES, true) => self::scalarFits($type, $element),
            $type === 'choice' => self::choiceFits($field, $element),
            $type === 'record' => is_int($element) && $element > 0 || ToolCallLinks::refName($element) !== null,
            default => true,
        };

        return $fits ? null : self::typeProblem($type, $element, $label);
    }

    private static function scalarFits(string $type, mixed $element): bool
    {
        return match ($type) {
            'string' => is_string($element),
            'integer' => is_int($element),
            'decimal' => is_int($element) || is_float($element) || is_string($element) && is_numeric($element),
            'date' => is_string($element) && self::isDate($element),
            'datetime' => is_string($element) && strtotime($element) !== false,
            default => is_bool($element),
        };
    }

    /**
     * @param  array<array-key, mixed>  $field
     */
    private static function choiceFits(array $field, mixed $element): bool
    {
        $options = is_array($field['options'] ?? null) ? array_column($field['options'], 'code') : [];

        return is_string($element) && in_array($element, $options, true);
    }

    private static function isDate(string $value): bool
    {
        $parts = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $matches) === 1 ? $matches : null;

        return $parts !== null && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private static function typeProblem(string $type, mixed $element, string $label): string
    {
        return match ($type) {
            'choice' => is_string($element)
                ? "{$label} の選択肢に、コード {$element} は無い。"
                : "{$label} は選択肢のコード(文字列)で書く。",
            'record' => "{$label} はレコードの id(整数)か、前の件を指す {\"ref\": \"<仮の名前>\"} で書く。",
            'date' => "{$label} は日付 YYYY-MM-DD で書く。",
            'datetime' => "{$label} は日時(ISO 8601)で書く。",
            'decimal' => "{$label} は数(小数は文字列でもよい)で書く。",
            default => "{$label} の値の形が型 {$type} に合わない。",
        };
    }
}
