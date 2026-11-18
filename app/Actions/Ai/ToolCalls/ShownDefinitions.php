<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Core\ConversationMarkers;
use App\Actions\Ai\Instructions\InstructionBuilder;

/**
 * 道具の説明に入れる定義の名前の写し(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1))。業務モジュール・項目・選択肢・
 * 参照先の名前に会話の印の形の直しを当てる。コードは英小文字・数字・_ の規則で会話の印の形を作れないので当てない。定義そのものは直さず、
 * B1 の確かめと書く値は直す前の定義を使う。
 */
final class ShownDefinitions
{
    /**
     * @param  array<string, mixed>  $definition  業務モジュールの定義
     * @param  list<string>  $forms  会話の印の形({@see InstructionBuilder::conversationMarkerForms()})
     * @return array<string, mixed>
     */
    public static function of(array $definition, array $forms): array
    {
        $shown = self::named($definition, $forms);
        $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
        $shown['fields'] = array_map(
            fn (mixed $field): mixed => is_array($field) ? self::field($field, $forms) : $field,
            $fields,
        );

        return $shown;
    }

    /**
     * @param  array<array-key, mixed>  $field
     * @param  list<string>  $forms
     * @return array<array-key, mixed>
     */
    private static function field(array $field, array $forms): array
    {
        $field = self::named($field, $forms);
        $options = is_array($field['options'] ?? null) ? $field['options'] : [];
        $field['options'] = array_map(
            fn (mixed $option): mixed => is_array($option) ? self::named($option, $forms) : $option,
            $options,
        );

        if (is_array($field['referencedModule'] ?? null)) {
            $field['referencedModule'] = self::named($field['referencedModule'], $forms);
        }

        return $field;
    }

    /**
     * @param  array<array-key, mixed>  $item
     * @param  list<string>  $forms
     * @return array<array-key, mixed>
     */
    private static function named(array $item, array $forms): array
    {
        if (is_string($item['name'] ?? null)) {
            $item['name'] = ConversationMarkers::broken($item['name'], $forms);
        }

        return $item;
    }
}
