<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\StartConversationRun;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use LogicException;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;

/**
 * 会話のジョブの 1(docs の 設計/AI/エージェントの実行/README.md 9.1 のジョブの順、写す値 = 設計/AI/実行の記録.md 2.1)。
 */
final class StartConversationRunTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
    }

    public function test_the_run_starts_with_the_purposes_copied(): void
    {
        $run = AiRun::factory()->conversation()->create(['kind' => RecordingConversationKind::CODE]);

        [, $kind] = app(StartConversationRun::class)->execute($run->id) ?? [null, null];

        $this->assertSame($this->conversationKind, $kind);
        $saved = AiRun::query()->findOrFail($run->id);
        $this->assertSame(AiRunStatus::Running, $saved->status);
        $this->assertFalse($saved->compacted);
        $this->assertNotNull($saved->started_at);
        $this->assertEquals(
            ['provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'max_output_tokens' => 1000],
            $saved->purposes['conversation'] ?? null,
        );
        $this->assertSame(100, $saved->purposes['title']['max_output_tokens'] ?? null);
    }

    /** 種類の実装が引けなければ処理中にしない(呼び手が失敗にする) */
    public function test_an_unregistered_kind_keeps_the_run_queued(): void
    {
        $run = AiRun::factory()->conversation()->create(['kind' => 'missing.conversation']);

        $started = app(StartConversationRun::class)->execute($run->id);

        $this->assertNotNull($started);
        $this->assertNull($started[1]);
        $this->assertSame(AiRunStatus::Queued, AiRun::query()->findOrFail($run->id)->status);
    }

    /** もう終わった実行(待ちの間に止めた実行など)は変えない(S24) */
    public function test_a_finished_run_is_left_alone(): void
    {
        $run = AiRun::factory()->conversation()->failed()->create(['kind' => RecordingConversationKind::CODE]);

        $this->assertNull(app(StartConversationRun::class)->execute($run->id));
        $this->assertSame(AiRunStatus::Failed, AiRun::query()->findOrFail($run->id)->status);
    }

    public function test_a_running_run_is_a_construction_error(): void
    {
        $run = AiRun::factory()->conversation()->running()->create(['kind' => RecordingConversationKind::CODE]);

        $this->expectException(LogicException::class);

        app(StartConversationRun::class)->execute($run->id);
    }
}
