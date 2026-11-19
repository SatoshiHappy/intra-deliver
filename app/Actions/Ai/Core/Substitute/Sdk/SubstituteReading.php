<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use Illuminate\Support\Str;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

/**
 * 1 回の試しの応答の読み(確定の時の正本 = docs の 設計/AI/会話の中核の口と受け手.md 13.3、空の id に振る値と生の状態 =
 * 設計/AI/会話の中核.md 13.2)。確定を知らせた数と接続先が返したままの呼び出しの id を数えるのは、応答の終わりの項目に載せるため。
 */
final class SubstituteReading
{
    public int $items = 0;

    /** @var list<string> 接続先が返したままの呼び出しの id */
    public array $providerCallIds = [];

    public bool $assignedIds = false;

    private string $pending = '';

    public function __construct(public readonly SubstituteReader $reader) {}

    /** 流れの事象を 1 つ受ける */
    public function take(object $event): void
    {
        if ($event instanceof TextDelta) {
            $this->pending .= $event->delta;
            $this->reader->delta($event->delta);
        }

        if ($event instanceof ReasoningStart) {
            $this->confirmText();
        }

        if ($event instanceof ToolCallEvent) {
            $this->confirmText();
            $this->confirmCall($event->toolCall);
        }
    }

    /**
     * 応答が返った時の確定(流れでは残りの文、流さない応答では文と呼び出しの全部を 1 つの知らせで)。
     *
     * @param  array<array-key, ToolCall>  $toolCalls
     */
    public function finish(string $text, array $toolCalls, bool $streamed): void
    {
        if ($streamed) {
            $this->confirmText();

            return;
        }

        $blocks = $text === '' ? [] : [['type' => 'text', 'text' => $text]];

        foreach ($toolCalls as $call) {
            $blocks[] = $this->callBlock($call);
        }

        if ($blocks !== []) {
            $this->items++;
            $this->reader->confirmed($blocks);
        }
    }

    private function confirmText(): void
    {
        if ($this->pending === '') {
            return;
        }

        $this->items++;
        $this->reader->confirmed([['type' => 'text', 'text' => $this->pending]]);
        $this->pending = '';
    }

    private function confirmCall(ToolCall $call): void
    {
        $this->items++;
        $this->reader->confirmed([$this->callBlock($call)]);
    }

    /**
     * @return array{type: 'tool_call', id: string, name: string, arguments: array<string, mixed>,
     *     result_id: string|null}
     */
    private function callBlock(ToolCall $call): array
    {
        $this->providerCallIds[] = $call->id;
        $id = $call->id;

        if ($id === '') {
            $id = (string) Str::uuid();
            $this->assignedIds = true;
        }

        return [
            'type' => 'tool_call',
            'id' => $id,
            'name' => $call->name,
            'arguments' => $call->arguments,
            'result_id' => $call->resultId,
        ];
    }
}
