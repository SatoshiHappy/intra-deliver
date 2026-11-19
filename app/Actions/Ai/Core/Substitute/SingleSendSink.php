<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteReading;
use Laravel\Ai\Gateway\StepResponse;

/**
 * 1 回の送信の受け先: 確定したモデルの文を返す項目に集める(題の応答は会話の保存に書かない = docs の 設計/AI/会話の中核の口と受け手.md
 * 13.3)。途中の文の差分は知らせない(題は流さない)。
 */
final class SingleSendSink implements SubstituteSink
{
    /** @var list<ConversationItem> */
    public array $items = [];

    public function confirmed(string $responseId, int $stepId, array $blocks): void
    {
        $this->items[] = ConversationItem::modelText($responseId, $blocks);
    }

    public function delta(int $stepId, string $text): void {}

    public function responded(string $responseId, StepResponse $response, SubstituteReading $reading): void {}
}
