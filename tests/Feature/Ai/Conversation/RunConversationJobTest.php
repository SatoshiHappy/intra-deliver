<?php

namespace Tests\Feature\Ai\Conversation;

use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Jobs\Ai\RunConversationJob;
use App\Models\Ai\AiRun;
use RuntimeException;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の型のジョブ(docs の 設計/AI/実行と費用/README.md 4.5): 再試行しない・ジョブの失敗は worker が落ちたとして書く(S22)。
 */
final class RunConversationJobTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    public function test_a_failed_job_marks_the_run_worker_lost(): void
    {
        $this->registerTestConversationKind();
        $run = AiRun::factory()->conversation()->running()->create(['kind' => RecordingConversationKind::CODE]);
        $job = new RunConversationJob($run->id, 600);

        $job->failed(new RuntimeException('落ちた'));

        $saved = AiRun::query()->findOrFail($run->id);
        $this->assertSame(1, $job->tries);
        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::WorkerLost], [$saved->status, $saved->error_code]);
    }
}
