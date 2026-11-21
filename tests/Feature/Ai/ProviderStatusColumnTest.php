<?php

namespace Tests\Feature\Ai;

use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 応答の状態コードの列の起きない組を表の検査制約が止めること(docs の 設計/AI/実行の記録.md 2.1・2.3): 範囲(100〜599)の外・処理中(と待ち)の行・
 * 400 以上なのに失敗でないか使用量がある・会話の型の実行の行。範囲の境目は両側を通す。
 */
final class ProviderStatusColumnTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_a_step_has_a_status_only_in_range(): void
    {
        $failed = AiRunStep::factory()->failed();

        foreach ([100, 399, 400, 599] as $status) {
            $this->assertSame($status, $failed->create(['provider_status' => $status])->refresh()->provider_status);
        }

        foreach ([99, 600] as $status) {
            $this->assertRejectedBy(
                'ai_run_steps_provider_status_check',
                fn () => $failed->create(['provider_status' => $status]),
            );
        }
    }

    public function test_a_step_has_a_declined_status_only_when_failed_without_usage(): void
    {
        $check = 'ai_run_steps_status_provider_status_check';
        $declined = ['provider_status' => 400];

        $this->assertRejectedBy($check, fn () => AiRunStep::factory()->create($declined));
        $this->assertRejectedBy($check, fn () => AiRunStep::factory()->succeeded()->create($declined));
        $this->assertRejectedBy($check, fn () => AiRunStep::factory()->stopped()->create($declined));
        $this->assertRejectedBy($check, fn () => AiRunStep::factory()->failed()->withUsage()->create($declined));
        $this->assertRejectedBy($check, fn () => AiRunStep::factory()->create(['provider_status' => 200]));
        $this->assertSame(399, AiRunStep::factory()->failed()->withUsage()->create(['provider_status' => 399])
            ->refresh()->provider_status);
    }

    public function test_a_run_has_a_declined_status_only_when_a_text_run_failed_without_usage(): void
    {
        $check = 'ai_runs_status_provider_status_check';
        $declined = ['provider_status' => 429];

        $this->assertSame(429, AiRun::factory()->failed()->create($declined)->refresh()->provider_status);
        $this->assertRejectedBy('ai_runs_provider_status_check', fn () => AiRun::factory()->failed()
            ->create(['provider_status' => 600]));
        $this->assertRejectedBy($check, fn () => AiRun::factory()->create($declined));
        $this->assertRejectedBy($check, fn () => AiRun::factory()->running()->create($declined));
        $this->assertRejectedBy($check, fn () => AiRun::factory()->succeeded()->create($declined));
        $this->assertRejectedBy($check, fn () => AiRun::factory()->conversation()->failed()->create($declined));
    }
}
