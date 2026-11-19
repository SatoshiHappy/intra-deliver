<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\RunClock;
use App\Actions\Ai\Conversation\RunProgress;
use App\Actions\Ai\Core\RetryWaitStartedNotice;
use App\Actions\Ai\Core\TextDeltaNotice;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Carbon\CarbonImmutable;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Support\TransactionalTestCase;

/**
 * 途中の表示の書き込み(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.6)。書く間隔 = 2 秒。
 */
final class RunProgressTest extends TransactionalTestCase
{
    private const STARTED = '2026-11-19 10:00:00';

    private AiRun $run;

    private RunProgress $progress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::STARTED));
        $this->run = AiRun::factory()->conversation()->running()->create(['kind' => RecordingConversationKind::CODE]);
        $this->progress = new RunProgress($this->run->id, 2, new RunClock(CarbonImmutable::parse(self::STARTED), 60));
    }

    /** 途中の文はステップごとに区切り、前のステップの文を置き換えない(要素の鍵の順は jsonb が並べ替える) */
    public function test_the_partial_text_is_split_by_step(): void
    {
        $this->progress->delta(new TextDeltaNotice(7, 'こん'));
        $this->progress->delta(new TextDeltaNotice(7, 'にちは'));
        $this->progress->delta(new TextDeltaNotice(9, '次'));
        $this->progress->flush();

        $this->assertEquals(
            [['step_id' => 7, 'text' => 'こんにちは'], ['step_id' => 9, 'text' => '次']],
            $this->written(),
        );
    }

    /** 最初の差分はすぐ書き、その後は書く間隔(境の 2 秒を含む)が過ぎるかステップの終わりまで書かない */
    public function test_deltas_are_written_at_the_interval(): void
    {
        $this->progress->delta(new TextDeltaNotice(7, 'a'));
        $first = $this->firstText();

        $this->travelTo(CarbonImmutable::parse(self::STARTED)->addSecond());
        $this->progress->delta(new TextDeltaNotice(7, 'b'));
        $second = $this->firstText();

        $this->travelTo(CarbonImmutable::parse(self::STARTED)->addSeconds(2));
        $this->progress->delta(new TextDeltaNotice(7, 'c'));

        $this->assertSame(['a', 'a', 'abc'], [$first, $second, $this->firstText()]);
    }

    public function test_a_flush_without_new_text_writes_nothing(): void
    {
        $this->progress->flush();

        $this->assertSame(0, AiRunText::query()->count());
    }

    /** 送り直しを待つ間は待つ長さ(秒に切り上げ)と何回目かを持ち、待ちが終われば消す */
    public function test_the_retry_wait_is_shown_while_waiting(): void
    {
        $this->progress->retryWaitStarted(new RetryWaitStartedNotice(7, 1500, 2, 10, 529));
        $run = $this->run->fresh();
        $this->assertSame([2, 2], [$run?->retry_wait_seconds, $run?->retry_attempt]);

        $this->progress->retryWaitEnded();
        $run = $this->run->fresh();
        $this->assertSame([null, null], [$run?->retry_wait_seconds, $run?->retry_attempt]);
    }

    /**
     * @return list<array{step_id: int, text: string}>
     */
    private function written(): array
    {
        /** @var list<array{step_id: int, text: string}> */
        return AiRunText::query()->whereKey($this->run->id)->value('partial_text') === null
            ? []
            : AiRunText::query()->findOrFail($this->run->id)->partial_text;
    }

    /**
     * 書かれた最初のステップの文(まだ無ければ null)。
     *
     * @phpstan-impure
     */
    private function firstText(): ?string
    {
        return $this->written()[0]['text'] ?? null;
    }
}
