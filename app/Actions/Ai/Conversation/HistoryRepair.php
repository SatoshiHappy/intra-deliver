<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\ConversationItemType;

/**
 * 読み込みの直し(規則の正本 = docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.8 の「読み込みの直し」、順 = 設計/AI/会話の中核.md 13.2)。
 * 前の実行までに結ばれた項目に当て、今の実行に結ばれた項目は呼び手が後ろにつなぐ。
 */
final class HistoryRepair
{
    private const TAIL_TYPES = [
        ConversationItemType::UserText,
        ConversationItemType::ModelText,
        ConversationItemType::ToolResult,
    ];

    /**
     * @param  list<ConversationItem>  $items  前の実行までに結ばれた項目(読んだ順)
     * @param  bool  $workerLost  前の実行が失敗(worker が落ちた)で終わったか
     * @param  list<string>  $awaitingCallIds  外さない承認待ちの呼び出し
     * @return list<ConversationItem|TransientText>
     */
    public static function repaired(array $items, bool $workerLost, array $awaitingCallIds = []): array
    {
        $kept = self::withoutUnresolvedCalls(self::withoutCancelled($items), $awaitingCallIds);
        $kept = array_values(array_filter($kept, static fn (ConversationItem $item): bool => ! self::isBlank($item)));
        $kept = self::withoutEmptiedEnds($items, $kept);

        return $workerLost ? self::withTail($kept) : $kept;
    }

    /**
     * 取り消しの印が指す応答の項目を外す。
     *
     * @param  list<ConversationItem>  $items
     * @return list<ConversationItem>
     */
    private static function withoutCancelled(array $items): array
    {
        $cancelled = [];

        foreach ($items as $item) {
            if ($item->type === ConversationItemType::Cancellation) {
                $cancelled[$item->content['response_id']] = true;
            }
        }

        return array_values(array_filter($items, static function (ConversationItem $item) use ($cancelled): bool {
            $responseId = self::responseIdOf($item);

            return $responseId === null || ! isset($cancelled[$responseId]);
        }));
    }

    /**
     * 結果の無い呼び出しのブロックを外し、ブロックが残らないモデルの文を外す(同じ項目の文のブロックは残す。外すのは結果の無い呼び出しだけで、
     * モデルが書いた文は消さないため)。
     *
     * @param  list<ConversationItem>  $items
     * @param  list<string>  $awaitingCallIds
     * @return list<ConversationItem>
     */
    private static function withoutUnresolvedCalls(array $items, array $awaitingCallIds): array
    {
        $resolved = array_fill_keys($awaitingCallIds, true);

        foreach ($items as $item) {
            if ($item->type === ConversationItemType::ToolResult) {
                $resolved[$item->content['call_id']] = true;
            }
        }

        $kept = [];

        foreach ($items as $item) {
            $calls = RunToolStep::callsIn([$item]);
            $unresolved = array_filter($calls, static fn (string $id): bool => ! isset($resolved[$id]));
            $trimmed = $unresolved === [] ? $item : self::withoutCalls($item, $unresolved);

            if ($trimmed !== null) {
                $kept[] = $trimmed;
            }
        }

        return $kept;
    }

    /**
     * @param  array<int, string>  $callIds
     */
    private static function withoutCalls(ConversationItem $item, array $callIds): ?ConversationItem
    {
        $blocks = array_values(array_filter(
            $item->content['blocks'],
            static fn (array $block): bool => $block['type'] !== 'tool_call'
                || ! in_array($block['id'], $callIds, true),
        ));

        return $blocks === [] ? null : ConversationItem::modelText($item->content['response_id'], $blocks);
    }

    /**
     * 応答の項目がすべて外れた応答の終わりを外す。
     *
     * @param  list<ConversationItem>  $before
     * @param  list<ConversationItem>  $kept
     * @return list<ConversationItem>
     */
    private static function withoutEmptiedEnds(array $before, array $kept): array
    {
        $had = self::modelResponses($before);
        $has = self::modelResponses($kept);

        return array_values(array_filter($kept, static fn (ConversationItem $item): bool => $item->type
            !== ConversationItemType::ResponseEnd
            || ! isset($had[$item->content['response_id']])
            || isset($has[$item->content['response_id']])));
    }

    /**
     * 末尾の判定(9.8)で足す文を、末尾の項目のすぐ後ろに置く。
     *
     * @param  list<ConversationItem>  $items
     * @return list<ConversationItem|TransientText>
     */
    private static function withTail(array $items): array
    {
        $tail = null;

        foreach ($items as $index => $item) {
            if (in_array($item->type, self::TAIL_TYPES, true)) {
                $tail = $index;
            }
        }

        $added = match ($tail === null ? null : $items[$tail]->type) {
            ConversationItemType::ToolResult => TransientText::resumeAfterCut(),
            ConversationItemType::UserText => TransientText::noResponse(),
            default => null,
        };

        if ($tail === null || $added === null) {
            return $items;
        }

        return [...array_slice($items, 0, $tail + 1), $added, ...array_slice($items, $tail + 1)];
    }

    /** 文のブロックだけを持ち、どれも空白だけのモデルの文か */
    private static function isBlank(ConversationItem $item): bool
    {
        if ($item->type !== ConversationItemType::ModelText || $item->content['blocks'] === []) {
            return false;
        }

        foreach ($item->content['blocks'] as $block) {
            if ($block['type'] !== 'text' || trim($block['text']) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * モデルの文を持つ応答の id。
     *
     * @param  list<ConversationItem>  $items
     * @return array<string, true>
     */
    private static function modelResponses(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if ($item->type === ConversationItemType::ModelText) {
                $ids[$item->content['response_id']] = true;
            }
        }

        return $ids;
    }

    private static function responseIdOf(ConversationItem $item): ?string
    {
        return match ($item->type) {
            ConversationItemType::ModelText, ConversationItemType::ResponseEnd => $item->content['response_id'],
            default => null,
        };
    }
}
