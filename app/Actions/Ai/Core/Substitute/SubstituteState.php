<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\AppendNotice;
use App\Actions\Ai\Core\CoreNotifier;
use App\Actions\Ai\Core\NoticeReply;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\ToolResultNotice;
use App\Actions\Ai\Core\TransientText;

/**
 * ループの手元の状態(会話の保存に足す物の知らせ = docs の 設計/AI/会話の中核の口と受け手.md 13.3 の知らせの口)。
 */
final class SubstituteState
{
    public int $turn = 1;

    public bool $unknownWritesAdded = false;

    public int $compactFailures = 0;

    /** 今の道具の呼び出しを返した送信の種別(道具の位置の確かめに渡す) */
    public SendKind $kind = SendKind::Turn;

    /**
     * @param  list<ConversationItem|TransientText>  $history
     */
    public function __construct(
        private readonly CoreNotifier $notifier,
        public array $history,
        public readonly SubstituteThinking $thinking,
    ) {}

    /**
     * 会話の保存に足し、送る履歴に足す。
     *
     * @param  non-empty-list<ConversationItem>  $items
     *
     * @throws ConversationEnded 受け手が会話が無いを返したとき
     */
    public function append(array $items): NoticeReply
    {
        $reply = $this->notifier->notify(new AppendNotice($items));

        if ($reply->conversationGone) {
            throw new ConversationEnded;
        }

        array_push($this->history, ...$items);

        return $reply;
    }

    /** 受け手が会話の保存に書き終えた道具の結果を、知らせて送る履歴に足す */
    public function addToolResult(ConversationItem $result): void
    {
        $this->notifier->notify(new ToolResultNotice($result));
        $this->history[] = $result;
    }

    /**
     * 要約が済んだ: 送る履歴を境目と要約の文から始め直す(会話の保存には 1 つの知らせで足す = 設計/AI/会話の中核.md 13.2)。
     *
     * @param  non-empty-list<ConversationItem>  $items
     */
    public function compacted(array $items): void
    {
        $this->append($items);
        $this->history = $items;
        $this->thinking->compacted();
    }
}
