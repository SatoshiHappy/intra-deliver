<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemType;
use LogicException;

/**
 * 会話の保存の道具の結果の項目から、送る文を組む部品(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4 の「結果の保存と会話の印の形の直し」・
 * 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1))。印の形を送る時に直すのは、保存の値を変えずに、版を替えた後も今の版の印で
 * 直せるようにするため。
 */
final class ToolResultMessages
{
    /** 本文を入れる置き換え口 */
    private const BODY_PLACEHOLDER = 'preview';

    /**
     * @throws LogicException 道具の結果の項目でないとき(作りの誤り)
     */
    public static function render(ConversationItem $result, InstructionBuilder $builder): string
    {
        if ($result->type !== ConversationItemType::ToolResult) {
            throw new LogicException("道具の結果でない項目 {$result->type->value} は組めない");
        }

        $forms = $builder->conversationMarkerForms();
        usort($forms, fn (string $left, string $right): int => mb_strlen($right) <=> mb_strlen($left));
        $content = $result->content;
        $body = is_string($content['body'] ?? null) ? self::broken($content['body'], $forms) : null;
        $values = array_map(
            fn (string|array $value): string|array => is_string($value)
                ? self::broken($value, $forms)
                : array_map(fn (string $item): string => self::broken($item, $forms), $value),
            is_array($content['values'] ?? null) ? $content['values'] : [],
        );
        $template = is_string($content['template'] ?? null) ? AiInstructionTemplate::from($content['template']) : null;

        if ($template === null) {
            return $body ?? '';
        }

        return $builder->template($template, $body === null ? $values : [...$values, self::BODY_PLACEHOLDER => $body]);
    }

    /**
     * 文の中の会話の印の形を、印と読めない形に直す。
     *
     * @param  list<string>  $forms  長い順
     */
    private static function broken(string $text, array $forms): string
    {
        foreach ($forms as $form) {
            $text = str_replace($form, mb_substr($form, 0, 1).' '.mb_substr($form, 1), $text);
        }

        return $text;
    }
}
