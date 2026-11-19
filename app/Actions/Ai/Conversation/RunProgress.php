<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\RetryWaitStartedNotice;
use App\Actions\Ai\Core\TextDeltaNotice;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;

/**
 * 途中の表示の書き込み(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.6)。途中の文はステップごとに区切った列で、書く間隔ごとと
 * ステップの終わりに実行の記録へ書く。書く者はその実行のジョブだけなので、列を手元に持って丸ごと書く。
 */
final class RunProgress
{
    /** @var list<array{step_id: int, text: string}> */
    private array $partial = [];

    private bool $unwritten = false;

    private ?int $writtenAt = null;

    public function __construct(
        private readonly int $runId,
        private readonly int $intervalSeconds,
        private readonly RunClock $clock,
    ) {}

    public function delta(TextDeltaNotice $notice): void
    {
        $last = array_key_last($this->partial);

        if ($last !== null && $this->partial[$last]['step_id'] === $notice->stepId) {
            $this->partial[$last]['text'] .= $notice->delta;
        } else {
            $this->partial[] = ['step_id' => $notice->stepId, 'text' => $notice->delta];
        }

        $this->unwritten = true;
        $now = $this->clock->now()->getTimestamp();

        if ($this->writtenAt === null || $now - $this->writtenAt >= $this->intervalSeconds) {
            $this->flush();
        }
    }

    /** ステップの終わりに、まだ書いていない差分を書く */
    public function flush(): void
    {
        if (! $this->unwritten) {
            return;
        }

        AiRunText::query()->updateOrCreate(['ai_run_id' => $this->runId], ['partial_text' => $this->partial]);
        $this->unwritten = false;
        $this->writtenAt = $this->clock->now()->getTimestamp();
    }

    /** 送り直しを待つ間の待つ長さ(秒。切り上げ)と何回目か */
    public function retryWaitStarted(RetryWaitStartedNotice $notice): void
    {
        AiRun::query()->whereKey($this->runId)->update([
            'retry_wait_seconds' => intdiv($notice->waitMs + 999, 1000),
            'retry_attempt' => $notice->attempt,
        ]);
    }

    public function retryWaitEnded(): void
    {
        AiRun::query()->whereKey($this->runId)->update(['retry_wait_seconds' => null, 'retry_attempt' => null]);
    }
}
