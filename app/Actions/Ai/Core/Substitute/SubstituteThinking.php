<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\SendTarget;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\ConversationItemType;
use DateTimeImmutable;

/**
 * 思考の文脈の管理の指定(付ける送信と値の正本 = docs の 設計/AI/会話の中核.md 13.2 の「思考の文脈の管理」)。代わりの版は続きを
 * 送らないので、付ける送信は回の答えと要約だけ。旗を処理の中に覚えず履歴から引くのは、実行をまたいで同じ値にするため。
 */
final class SubstituteThinking
{
    private const IDLE_SECONDS = 3_600;

    /**
     * 要求に足す指定(付けない送信では空)。
     *
     * @param  list<ConversationItem|TransientText>  $history
     * @return array<string, mixed>
     */
    public static function options(array $history, SendTarget $target, SendKind $kind, DateTimeImmutable $now): array
    {
        $pattern = $target->traits->contextManagementModels;
        $sends = $kind === SendKind::Turn || $kind === SendKind::Compaction;

        $managed = $pattern !== null && preg_match($pattern, $target->model->id) === 1;

        if (! $sends || ! $managed || ! $target->model->usesThinking) {
            return [];
        }

        $times = self::endTimes($history);

        if ($kind === SendKind::Turn && $times !== []) {
            $times[] = $now->getTimestamp();
        }

        $keep = self::idled($times) ? ['type' => 'thinking_turns', 'value' => 1] : 'all';

        return ['context_management' => ['edits' => [['type' => 'clear_thinking_20251015', 'keep' => $keep]]]];
    }

    /**
     * @param  list<int>  $times
     */
    private static function idled(array $times): bool
    {
        foreach (array_slice($times, 1, null, true) as $index => $time) {
            if ($time - $times[$index - 1] > self::IDLE_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * 最後の境目より後ろの応答の終わりの時刻(秒)。
     *
     * @param  list<ConversationItem|TransientText>  $history
     * @return list<int>
     */
    private static function endTimes(array $history): array
    {
        $times = [];

        foreach ($history as $item) {
            if (! $item instanceof ConversationItem) {
                continue;
            }

            if ($item->type === ConversationItemType::CompactBoundary) {
                $times = [];
            }

            if ($item->type === ConversationItemType::ResponseEnd) {
                $times[] = (new DateTimeImmutable((string) $item->content['finished_at']))->getTimestamp();
            }
        }

        return $times;
    }
}
