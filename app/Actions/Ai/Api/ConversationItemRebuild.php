<?php

namespace App\Actions\Ai\Api;

use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemType;
use DateTimeImmutable;

/**
 * 会話の保存の項目の写しの中身から、{@see ConversationItem} の作り手で作り直す({@see ConversationItem::fromArray()} が形を確かめた後に呼ぶ)。
 */
final class ConversationItemRebuild
{
    /**
     * 形を確かめた中身から、作り手で作り直す(写しを持つ側が鍵の順を変えても、同じ値になる。モデルの文のブロックは {@see ConversationItem::modelText()} が作り直す)。
     *
     * @param  array<array-key, mixed>  $content
     */
    public static function of(ConversationItemType $type, array $content): ConversationItem
    {
        return match ($type) {
            ConversationItemType::UserText => ConversationItem::userText($content['text']),
            ConversationItemType::ModelText => ConversationItem::modelText(
                $content['response_id'],
                array_values($content['blocks']),
            ),
            ConversationItemType::ResponseEnd => ConversationItem::responseEnd(
                $content['response_id'],
                $content['usage'],
                $content['raw_state'],
                $content['provider'],
                $content['model'],
                $content['block_count'],
                $content['call_ids'],
                new DateTimeImmutable($content['finished_at']),
            ),
            ConversationItemType::Cancellation => ConversationItem::cancellation($content['response_id']),
            ConversationItemType::ToolResult => ConversationItem::toolResult(
                $content['call_id'],
                $content['template'] === null ? null : AiInstructionTemplate::from($content['template']),
                $content['values'],
                $content['body'],
                $content['is_error'],
                $content['rendered'],
            ),
            ConversationItemType::Interruption => ConversationItem::interruption(
                $content['during_tool'],
                $content['rendered'],
            ),
            ConversationItemType::TurnLimit => ConversationItem::turnLimit(),
            ConversationItemType::AddedText => ConversationItem::addedText(
                AiInstructionTemplate::from($content['template']),
                $content['values'],
                $content['call_ids'],
            ),
            ConversationItemType::CompactBoundary => ConversationItem::compactBoundary(
                $content['estimate'],
                $content['date'],
                $content['step_id'],
            ),
            ConversationItemType::CompactSummary => ConversationItem::compactSummary($content['text']),
        };
    }
}
