<?php

namespace App\Actions\Ai\ToolCalls;

/**
 * 定義の中の、ユーザー・組織を指す項目を読む部品(名前の引き当てと id の確かめが同じ読み方で項目を選ぶため。docs の 設計/AI/道具と権限/README.md
 * 10.2 のユーザー・組織の名前)。定義は実行の始めに読んだ定義(10.4)。
 *
 * @phpstan-type PartyType 'user'|'department'|'user_or_department'
 */
final class ToolPartyFields
{
    /**
     * 相手を指す型(ほかの型は null)。
     *
     * @return PartyType|null
     */
    public static function type(mixed $type): ?string
    {
        return match ($type) {
            'user' => 'user',
            'department' => 'department',
            'user_or_department' => 'user_or_department',
            default => null,
        };
    }

    /**
     * 相手を指す項目(項目のコード → 型)。$searchableOnly なら条件に使える項目だけ。
     *
     * @param  array<array-key, mixed>  $definition
     * @return array<string, PartyType>
     */
    public static function of(array $definition, bool $searchableOnly): array
    {
        $types = [];

        foreach (self::fields($definition) as $field) {
            $code = $field['code'] ?? null;
            $type = self::type($field['type'] ?? null);
            $searchable = ($field['isSearchable'] ?? false) === true;

            if (is_string($code) && $type !== null && ($searchable || ! $searchableOnly)) {
                $types[$code] = $type;
            }
        }

        return $types;
    }

    /**
     * 複数の値を持てる項目のコード。
     *
     * @param  array<array-key, mixed>  $definition
     * @return list<string>
     */
    public static function multiple(array $definition): array
    {
        $codes = [];

        foreach (self::fields($definition) as $field) {
            if (is_string($field['code'] ?? null) && ($field['isMultiple'] ?? false) === true) {
                $codes[] = $field['code'];
            }
        }

        return $codes;
    }

    /**
     * 明細の項目の、明細のモジュールの定義(項目のコード → 定義)。
     *
     * @param  array<array-key, mixed>  $definition
     * @return array<string, array<array-key, mixed>>
     */
    public static function children(array $definition): array
    {
        $children = [];

        foreach (self::fields($definition) as $field) {
            if (is_string($field['code'] ?? null) && is_array($field['childModule'] ?? null)) {
                $children[$field['code']] = $field['childModule'];
            }
        }

        return $children;
    }

    /**
     * @param  array<array-key, mixed>  $definition
     * @return list<array<array-key, mixed>>
     */
    private static function fields(array $definition): array
    {
        return is_array($definition['fields'] ?? null)
            ? array_values(array_filter($definition['fields'], is_array(...)))
            : [];
    }
}
