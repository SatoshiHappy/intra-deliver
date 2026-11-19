<?php

namespace Tests\Feature\Ai\Conversation;

use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Models\Ai\AiRun;
use App\Queries\Ai\RunChainStart;
use Carbon\CarbonImmutable;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の最初に始まった実行の始めの日(docs の 設計/AI/会話の中核.md 13.2): 鎖の実行のうち始めの時刻のある実行で鎖の根にいちばん近い物の日。
 * 待ちのまま終わった実行(待ちで止めた)は数えない。期待する日はどれも今の実行の日と違う。
 */
final class RunChainStartTest extends TransactionalTestCase
{
    public function test_a_root_that_never_started_is_skipped(): void
    {
        $root = $this->neverStarted(null);
        $started = $this->startedOn($root, '2026-11-17 01:00:00+00');
        $current = $this->startedOn($started, '2026-11-19 01:00:00+00');

        $this->assertSame('2026-11-17', app(RunChainStart::class)->firstRunDate($current->id));
    }

    public function test_the_started_run_nearest_the_root_wins(): void
    {
        $root = $this->startedOn(null, '2026-11-16 01:00:00+00');
        $skipped = $this->neverStarted($root);
        $current = $this->startedOn($skipped, '2026-11-19 01:00:00+00');

        $this->assertSame('2026-11-16', app(RunChainStart::class)->firstRunDate($current->id));
    }

    /** 状態では見ない(始めの時刻のある止めた実行は数える) */
    public function test_a_started_then_stopped_root_counts(): void
    {
        $root = AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'status' => AiRunStatus::Stopped,
            'stop_reason' => AiRunStopReason::User,
            'started_at' => CarbonImmutable::parse('2026-11-15 01:00:00+00'),
            'finished_at' => now(),
        ]);
        $skipped = $this->neverStarted($root);
        $current = $this->startedOn($skipped, '2026-11-19 01:00:00+00');

        $this->assertSame('2026-11-15', app(RunChainStart::class)->firstRunDate($current->id));
    }

    private function neverStarted(?AiRun $previous): AiRun
    {
        return AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'previous_run_id' => $previous?->id,
            'status' => AiRunStatus::Stopped,
            'stop_reason' => AiRunStopReason::User,
            'finished_at' => now(),
        ]);
    }

    private function startedOn(?AiRun $previous, string $startedAt): AiRun
    {
        return AiRun::factory()->conversation()->running()->create([
            'kind' => RecordingConversationKind::CODE,
            'previous_run_id' => $previous?->id,
            'started_at' => CarbonImmutable::parse($startedAt),
        ]);
    }
}
