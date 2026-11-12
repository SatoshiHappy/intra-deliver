<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\ToolCalls\ModelToolCall;
use App\Actions\Ai\ToolCalls\ToolCallWrapper;
use App\Actions\Ai\ToolCalls\ToolStep;
use App\Actions\Ai\ToolCalls\ToolStepOutcome;
use App\Enums\Ai\AiRunStopReason;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use Closure;
use LogicException;
use Tests\Feature\Ai\Tools\FakeRecordTool;

/**
 * 道具を呼ぶ包みのテストの準備: 2 段目の判定の準備({@see ArrangesToolJudgements})に、会話の保存の偽物と、実行のステップを足す。
 */
trait RunsToolCalls
{
    use ArrangesToolJudgements;

    private FakeConversationStore $store;

    private AiRunStep $step;

    private int $conversationId;

    private function arrangeWrapper(): void
    {
        $this->arrangeJudgements();
        FakeConversationStore::createTables();
        $this->store = new FakeConversationStore;
        $this->conversationId = $this->store->startConversation($this->run->id);
        $this->step = AiRunStep::factory()->create(['ai_run_id' => $this->run->id]);
    }

    /**
     * 呼び出しを包みに通す。
     *
     * @param  list<ModelToolCall>  $calls
     * @param  (Closure(): ?AiRunStopReason)|null  $stopped
     */
    private function wrap(array $calls, ?Closure $stopped = null): ToolStepOutcome
    {
        $step = new ToolStep($this->run, $this->step, $this->granted, $this->store);

        return app(ToolCallWrapper::class)->run($step, $calls, $stopped ?? fn (): ?AiRunStopReason => null);
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    private static function toolCall(string $id, string $name, array $arguments = []): ModelToolCall
    {
        return new ModelToolCall($id, $name, $arguments);
    }

    /** 渡した偽の道具 */
    private function tool(string $name): FakeRecordTool
    {
        $tool = $this->granted->find($name)?->tool;

        return $tool instanceof FakeRecordTool ? $tool : throw new LogicException("{$name} を渡していない");
    }

    /** 呼び出しの id の道具の行 */
    private function row(string $callId): AiToolCall
    {
        return AiToolCall::query()->where('ai_run_id', $this->run->id)->where('call_id', $callId)->sole();
    }

    /**
     * 会話の保存の項目の中身(足した順)。
     *
     * @return list<array<string, mixed>>
     */
    private function storedContents(): array
    {
        $items = $this->store->read($this->run->id) ?? [];

        return array_map(fn (ConversationItem $item): array => $item->content, $items);
    }
}
