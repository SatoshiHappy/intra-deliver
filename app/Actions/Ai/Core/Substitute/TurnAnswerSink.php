<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\CoreClock;
use App\Actions\Ai\Core\CoreNotifier;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\SendTarget;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteReading;
use App\Actions\Ai\Core\TextDeltaNotice;
use Laravel\Ai\Gateway\StepResponse;

/**
 * 回の答えの送信の受け先(知らせと動かさない呼び出しの正本 = docs の 設計/AI/会話の中核の口と受け手.md 13.3 の知らせの口)。
 */
final class TurnAnswerSink implements SubstituteSink
{
    /** @var list<CoreToolCall> 動かす呼び出し(確定した順) */
    public array $calls = [];

    /** @var array<string, true> この応答で確定した呼び出しの id */
    private array $seen = [];

    public function __construct(
        private readonly SubstituteState $state,
        private readonly CoreNotifier $notifier,
        private readonly CoreClock $clock,
        private readonly SendTarget $target,
    ) {}

    public function confirmed(string $responseId, int $stepId, array $blocks): void
    {
        $reply = $this->state->append([ConversationItem::modelText($responseId, $blocks)]);
        $returned = array_fill_keys($reply->duplicateCallIds, true);

        foreach ($blocks as $block) {
            if ($block['type'] !== 'tool_call') {
                continue;
            }

            if (! isset($this->seen[$block['id']]) && ! isset($returned[$block['id']])) {
                $this->calls[] = new CoreToolCall($block['id'], $block['name'], $block['arguments']);
            }

            $this->seen[$block['id']] = true;
        }
    }

    public function delta(int $stepId, string $text): void
    {
        $this->notifier->notify(new TextDeltaNotice($stepId, $text));
    }

    public function responded(string $responseId, StepResponse $response, SubstituteReading $reading): void
    {
        $raw = $reading->assignedIds || $response->replayBlocks === [] ? null : $response->replayBlocks;
        $this->state->append([ConversationItem::responseEnd(
            $responseId,
            SubstituteUsage::of($response->usage),
            $raw,
            $this->target->providerName,
            $this->target->model->id,
            $reading->items,
            $reading->providerCallIds,
            $this->clock->now(),
        )]);
    }

    /** この応答で呼び出しが 1 つでも確定したか(動かす呼び出しが重なりで空でも、道具の段を通すため) */
    public function hadCalls(): bool
    {
        return $this->seen !== [];
    }

    /**
     * 動かす呼び出しの id。
     *
     * @return list<string>
     */
    public function callIds(): array
    {
        return array_map(static fn (CoreToolCall $call): string => $call->callId, $this->calls);
    }
}
