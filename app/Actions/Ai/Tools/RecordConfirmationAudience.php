<?php

namespace App\Actions\Ai\Tools;

use App\Actions\Ai\Api\RecordCurrentValues;

/**
 * 確認の内容の、見られる人が変わる件の印({@see RecordConfirmations} の中だけで使う。docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.5)。
 * 参照制限のチェックか見せる人、または関係者の印の付いた項目(親と明細の行)の相手が、今の値と違う件に付ける。作るの今の値 = 業務モジュールの既定
 * (参照制限)と空(関係者の項目)。印は実行の始めの定義の関係者の印で付ける。相手は種類と id の組で比べ、並びは見ない。
 */
final class RecordConfirmationAudience
{
    /**
     * @param  array<array-key, mixed>  $definition
     * @param  array<array-key, mixed>  $values  書く値(入力の形)
     * @param  array<array-key, mixed>  $details  書く行(入力の形)
     * @param  array{current: array{restricted: bool, viewers: list<array{type: string, id: int}>},
     *     next: array{restricted: bool, viewers: list<array{type: string, id: int}>}}|null  $rowAccess
     * @return array{rowAccess: bool, fields: list<string>, details: list<string>}
     */
    public static function changes(
        array $definition,
        array $values,
        array $details,
        ?array $rowAccess,
        ?RecordCurrentValues $current,
    ): array {
        $fields = [];

        foreach (self::grantingFields($definition) as $code => $type) {
            $currentKeys = self::keys($current?->values[$code] ?? null, $type);

            if (array_key_exists($code, $values) && self::keys($values[$code], $type) !== $currentKeys) {
                $fields[] = $code;
            }
        }

        return [
            'rowAccess' => $rowAccess !== null
                && self::rowAccessKeys($rowAccess['current']) !== self::rowAccessKeys($rowAccess['next']),
            'fields' => $fields,
            'details' => self::details($definition, $details, $current),
        ];
    }

    /**
     * 関係者の印の付いた項目を持つ明細の表のうち、行の相手が今の行と違う表。
     *
     * @param  array<array-key, mixed>  $definition
     * @param  array<array-key, mixed>  $details
     * @return list<string>
     */
    private static function details(array $definition, array $details, ?RecordCurrentValues $current): array
    {
        $changed = [];

        foreach (RecordToolValues::fields($definition) as $field) {
            $code = is_string($field['code'] ?? null) ? $field['code'] : null;
            $types = self::grantingFields(is_array($field['childModule'] ?? null) ? $field['childModule'] : []);

            if ($code === null || $types === [] || ! array_key_exists($code, $details)) {
                continue;
            }

            $rows = is_array($details[$code]) ? array_filter($details[$code], is_array(...)) : [];
            $next = array_column($rows, 'values');
            $now = array_column($current->details[$code] ?? [], 'values');

            if (self::rowKeys($next, $types) !== self::rowKeys($now, $types)) {
                $changed[] = $code;
            }
        }

        return $changed;
    }

    /**
     * 関係者の印の付いた、相手を指す項目(コード → 型)。
     *
     * @param  array<array-key, mixed>  $definition
     * @return array<string, string>
     */
    private static function grantingFields(array $definition): array
    {
        $fields = [];

        foreach (RecordToolValues::fields($definition) as $field) {
            $grants = ($field['grantsRowAccess'] ?? false) === true;

            if ($grants && is_string($field['code'] ?? null) && is_string($field['type'] ?? null)) {
                $fields[$field['code']] = $field['type'];
            }
        }

        return $fields;
    }

    /**
     * 行の列の、関係者の項目の相手の組(並びを見ない)。
     *
     * @param  list<mixed>  $rows
     * @param  array<string, string>  $types
     * @return list<string>
     */
    private static function rowKeys(array $rows, array $types): array
    {
        $keys = [];

        foreach ($rows as $values) {
            foreach ($types as $code => $type) {
                $keys = [...$keys, ...self::keys(is_array($values) ? ($values[$code] ?? null) : null, $type)];
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * 値の相手の組(入力の形の id・{type, id}、画面の初期値の形の参照の値のどれでも。並びを見ない)。
     *
     * @return list<string>
     */
    private static function keys(mixed $value, string $type): array
    {
        $parties = is_array($value) && array_is_list($value) ? $value : [$value];
        $keys = array_values(array_filter(array_map(fn (mixed $party): ?string => self::key($party, $type), $parties)));
        sort($keys);

        return array_values(array_unique($keys));
    }

    /** 相手 1 つの種類:id(相手でなければ null) */
    private static function key(mixed $party, string $type): ?string
    {
        $id = is_array($party) ? ($party['id'] ?? null) : $party;
        $kind = $type === 'user_or_department' && is_array($party) ? ($party['type'] ?? null) : $type;

        return is_int($id) && ($kind === 'user' || $kind === 'department') ? "{$kind}:{$id}" : null;
    }

    /**
     * @param  array{restricted: bool, viewers: list<array{type: string, id: int}>}  $rowAccess
     * @return array{bool, list<string>}
     */
    private static function rowAccessKeys(array $rowAccess): array
    {
        $viewers = array_map(fn (array $viewer): string => "{$viewer['type']}:{$viewer['id']}", $rowAccess['viewers']);
        sort($viewers);

        return [$rowAccess['restricted'], $viewers];
    }
}
