<?php

namespace App\Actions\Ai\Api;

/**
 * 会話の依頼の口が書いた実行と、呼び手が同じトランザクションで会話の保存に足す項目の列(docs の 設計/AI/エージェントの実行/口と契約.md 9.12)。
 */
final readonly class ConversationRequested
{
    /**
     * @param  list<ConversationItem>  $items  文を送る依頼は利用者の文の項目か承認待ちへの理由つきの断り、確認に答える依頼は空
     */
    public function __construct(public int $runId, public array $items) {}
}
