<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemType;
use DateTimeImmutable;
use DateTimeZone;

/**
 * 先頭の日付の文の値と、日付が変わった知らせの比べる元(docs の 設計/AI/会話の中核.md 13.2)。日は東京の日で数える。
 */
final class SubstituteDates
{
    private const ZONE = 'Asia/Tokyo';

    /**
     * 先頭の日付の文の値(送る履歴の最後の要約の境目の日。無ければ会話の最初の実行の始めの日)。
     *
     * @param  list<ConversationItem|TransientText>  $history
     */
    public static function head(array $history, string $firstRunDate): string
    {
        $date = $firstRunDate;

        foreach (self::items($history) as $item) {
            if ($item->type === ConversationItemType::CompactBoundary) {
                $date = (string) $item->content['date'];
            }
        }

        return $date;
    }

    /**
     * 最後に知らせた日(最後の境目より後ろの最新の日付が変わった知らせの日。無ければ先頭の日付の文の値)。
     *
     * @param  list<ConversationItem|TransientText>  $history
     */
    public static function lastNotified(array $history, string $firstRunDate): string
    {
        $date = $firstRunDate;

        foreach (self::items($history) as $item) {
            if ($item->type === ConversationItemType::CompactBoundary) {
                $date = (string) $item->content['date'];
            }

            if (self::isDateChange($item)) {
                $date = (string) $item->content['values']['new_date'];
            }
        }

        return $date;
    }

    public static function today(DateTimeImmutable $now): string
    {
        return $now->setTimezone(new DateTimeZone(self::ZONE))->format(ConversationItem::DATE_FORMAT);
    }

    /**
     * @param  list<ConversationItem|TransientText>  $history
     * @return list<ConversationItem>
     */
    private static function items(array $history): array
    {
        return array_values(array_filter($history, static fn ($item): bool => $item instanceof ConversationItem));
    }

    private static function isDateChange(ConversationItem $item): bool
    {
        return $item->type === ConversationItemType::AddedText
            && $item->content['template'] === AiInstructionTemplate::DateChange->value;
    }
}
