<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Conversation\StartConversationRun;
use App\Actions\Ai\Conversation\StepTargetsOf;
use App\Actions\Ai\Core\SendKind;
use App\Enums\Ai\AiConversationPurpose;
use App\Models\Ai\AiRun;
use LogicException;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;

/**
 * 送る先は実行の記録に写した用途の接続先とモデルと、その性質(docs の 設計/AI/実行の記録.md 2.1、9.1 のジョブの 1)。
 */
final class StepTargetsOfTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    /** 写しの後に設定のモデルが変わっても、写しのモデルで送る */
    public function test_the_targets_come_from_the_snapshot(): void
    {
        $this->registerTestConversationKind();
        $run = AiRun::factory()->conversation()->create(['kind' => RecordingConversationKind::CODE]);
        app(StartConversationRun::class)->execute($run->id);
        config(['ai_runs.conversation.purposes.conversation.model' => 'claude-haiku-5-5']);

        $targets = app(StepTargetsOf::class)->targets(AiRun::query()->findOrFail($run->id));

        $conversation = $targets->conversation;
        $this->assertSame(['anthropic', 'claude-sonnet-5-5'], [$conversation->provider, $conversation->model]);
        $this->assertSame(['anthropic', 'claude-haiku-5-5'], [$targets->title->provider, $targets->title->model]);
        $this->assertSame(AiConversationPurpose::Title, $targets->for(SendKind::Title)->purpose);
        $this->assertSame(AiConversationPurpose::Conversation, $targets->for(SendKind::Compaction)->purpose);
    }

    public function test_a_run_without_the_snapshot_is_a_construction_error(): void
    {
        $run = AiRun::factory()->conversation()->create(['kind' => RecordingConversationKind::CODE]);

        $this->expectException(LogicException::class);
        app(StepTargetsOf::class)->targets($run);
    }
}
