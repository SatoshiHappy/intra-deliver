<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\ConversationStore;
use LogicException;

/**
 * 並べて動かす組の呼び出しの結果を、会話の保存に書かずに受け取る口(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4 の並べて動かす組:
 * 会話の保存への結果の書き込みは worker が呼び出しの順に行う)。包みは 1 つずつの組と同じ手順で書き、足した項目をここに溜める。
 */
final class CollectedItems implements ConversationStore
{
    /** @var list<ConversationItem> */
    public array $items = [];

    /**
     * @throws LogicException いつも(組の中で会話の保存を読まない)
     */
    public function read(int $runId): ?array
    {
        throw new LogicException('並べて動かす組の中で会話の保存を読んだ');
    }

    public function append(int $runId, array $items): bool
    {
        array_push($this->items, ...$items);

        return true;
    }
}
