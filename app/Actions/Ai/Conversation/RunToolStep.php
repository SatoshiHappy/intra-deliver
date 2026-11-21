<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\ConversationStore;
use App\Actions\Ai\Api\PriorToolCall;
use App\Actions\Ai\ToolCalls\AnswerRunContext;
use App\Actions\Ai\ToolCalls\GrantedTools;
use App\Actions\Ai\ToolCalls\ModelToolCall;
use App\Actions\Ai\ToolCalls\ToolStep;
use App\Enums\Ai\AiRunStepStatus;
use App\Enums\Ai\ConversationItemType;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use DateTimeImmutable;
use LogicException;

/**
 * 道具を動かす口と知らせの口が分け持つ、今のステップの呼び出しの並び。呼び出しは応答に呼び出しが入ったステップ(回の答えか続きの最後の送信)に
 * 属し、ステップの中の順で数える(docs の 設計/AI/実行の記録.md 2.4)。中核が受け手を通らずに結果を付けた呼び出しの行にも、確定した
 * モデルの文から覚えた名前と引数を使う。
 */
final class RunToolStep
{
    private ?AiRunStep $step = null;

    private int $position = 0;

    /** @var list<PriorToolCall> */
    private array $prior = [];

    /** @var array<string, ModelToolCall> */
    private array $calls = [];

    public function __construct(
        private readonly AiRun $run,
        private readonly GrantedTools $tools,
        private readonly ConversationStore $store,
        private readonly ?AnswerCalls $answer = null,
    ) {}

    /** 回の答えか続きの送信のステップに替わった(送り直しも、応答はその最後の試しのステップに属する) */
    public function begin(int $stepId): void
    {
        $this->step = AiRunStep::query()->whereKey($stepId)->firstOrFail();
        $this->position = 0;
        $this->prior = [];
        $this->calls = [];
    }

    /**
     * 確定したモデルの文の呼び出しを覚える。
     *
     * @param  list<ConversationItem>  $items
     */
    public function remember(array $items): void
    {
        foreach (self::callBlocks($items) as $block) {
            $this->calls[$block['id']] = new ModelToolCall($block['id'], $block['name'], $block['arguments']);
        }
    }

    /**
     * 確定したモデルの文の呼び出しの id(項目の順)。
     *
     * @param  list<ConversationItem>  $items
     * @return list<string>
     */
    public static function callsIn(array $items): array
    {
        return array_column(self::callBlocks($items), 'id');
    }

    /**
     * @param  list<ConversationItem>  $items
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private static function callBlocks(array $items): array
    {
        $blocks = [];

        foreach ($items as $item) {
            if ($item->type !== ConversationItemType::ModelText) {
                continue;
            }

            foreach ($item->content['blocks'] as $block) {
                if ($block['type'] === 'tool_call') {
                    $blocks[] = ['id' => $block['id'], 'name' => $block['name'], 'arguments' => $block['arguments']];
                }
            }
        }

        return $blocks;
    }

    public function tools(): GrantedTools
    {
        return $this->tools;
    }

    /**
     * 呼び出しの中で成功した最後の送信の完了の時刻(ファイルを作るの中の呼び出しのステップの行。無ければ null = 思考の文脈の管理の最後の
     * 完了の時刻 = docs の 設計/AI/会話の中核.md 13.2)。
     */
    public function lastSendFinishedAt(string $callId): ?DateTimeImmutable
    {
        $finished = AiRunStep::query()
            ->join('ai_tool_calls', 'ai_tool_calls.id', '=', 'ai_run_steps.ai_tool_call_id')
            ->where('ai_run_steps.ai_run_id', $this->run->id)
            ->where('ai_tool_calls.call_id', $callId)
            ->where('ai_run_steps.status', AiRunStepStatus::Succeeded)
            ->max('ai_run_steps.finished_at');

        return is_string($finished) ? new DateTimeImmutable($finished) : null;
    }

    public function toolStep(): ToolStep
    {
        $step = $this->step ?? throw new LogicException('呼び出しの前に回の答えか続きの送信が無い');

        return new ToolStep($this->run, $step, $this->tools, $this->store);
    }

    /** 答えの実行の承認した件(「実行」と答えた答えの実行だけ) */
    public function answer(): ?AnswerCalls
    {
        return $this->answer;
    }

    /** 承認した件を通す時 */
    public function answerContext(): AnswerRunContext
    {
        return new AnswerRunContext($this->run, $this->tools, $this->store);
    }

    public function nextPosition(): int
    {
        return ++$this->position;
    }

    /** @return list<PriorToolCall> */
    public function prior(): array
    {
        return $this->prior;
    }

    public function passed(PriorToolCall $call): void
    {
        $this->prior[] = $call;
    }

    public function callOf(string $callId): ModelToolCall
    {
        return $this->calls[$callId] ?? throw new LogicException("呼び出し {$callId} が確定したモデルの文に無い");
    }
}
