<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;

/**
 * 道具の結果の知らせ(docs の 設計/AI/会話の中核の口と受け手.md 13.3。記録と途中の表示のため)。包みが会話の保存に書き終えた結果なので、
 * 繋ぎは会話の保存に書かない。
 */
final readonly class ToolResultNotice implements CoreNotice
{
    public function __construct(public ConversationItem $result) {}
}
