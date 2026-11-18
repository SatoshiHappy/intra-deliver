<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;

/**
 * 会話の保存に足す項目の知らせ(docs の 設計/AI/会話の中核の口と受け手.md 13.3。足す項目と、繋ぎが書く物も同じ項)。繋ぎは項目の列を
 * 1 つの足すで書く(全部か無しか)。
 */
final readonly class AppendNotice implements CoreNotice
{
    /**
     * @param  non-empty-list<ConversationItem>  $items
     */
    public function __construct(public array $items) {}
}
