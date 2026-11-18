<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\ConversationMarkers;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemType;
use LogicException;

/**
 * 会話の保存の道具の結果の項目から、送る文を組む部品(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4 の「結果の保存と会話の印の形の直し」・
 * 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.6 の (1))。印の形を送る時に直すのは、保存の値を変えずに、版を替えた後も今の版の印で
 * 直せるようにするため。
 *
 * @phpstan-import-type ToolResultContent from ConversationItem
 */
final class ToolResultMessages
{
    /**
     * @throws LogicException 道具の結果の項目でないとき・定型も本文も無いとき(どちらも作りの誤り)
     */
    public static function render(ConversationItem $result, InstructionBuilder $builder): string
    {
        if ($result->type !== ConversationItemType::ToolResult) {
            throw new LogicException("道具の結果でない項目 {$result->type->value} は組めない");
        }

        // 項目は形の確かめを通った中身だけを持つ(ConversationItem::of)ので、道具の結果の欄の形として読む
        /** @var ToolResultContent $content */
        $content = $result->content;
        $forms = $builder->conversationMarkerForms();
        $body = $content['body'] === null ? null : ConversationMarkers::broken($content['body'], $forms);
        $values = array_map(
            fn (string|array $value): string|array => is_string($value)
                ? ConversationMarkers::broken($value, $forms)
                : array_map(fn (string $item): string => ConversationMarkers::broken($item, $forms), $value),
            $content['values'],
        );

        if ($content['template'] === null) {
            return $body ?? throw new LogicException('定型も本文も無い道具の結果は作れない(形の確かめが止める)');
        }

        $template = AiInstructionTemplate::from($content['template']);

        $filled = $body === null ? $values : [...$values, AiInstructionTemplate::BODY_VALUE_KEY => $body];

        return $builder->template($template, $filled);
    }
}
