<?php

namespace Tests\Feature\Reset;

use App\Queries\Reset\ResetTables;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 初期化の記録の表の制約(docs の 設計/公開の配備/毎日の初期化.md 2 節・配備と戻し方.md 6.3 の「5 の結果と次の操作」の「起きない」行)。
 * 加えて、処理中のジョブの数え方の境目(予約から retry_after ちょうどで過ぎたと見る = DatabaseQueue と同じ)を確かめる。
 */
final class ResetRunsTableTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_a_succeeded_row_has_the_mark_of_reloading(): void
    {
        $succeeded = ['result' => 'succeeded', 'finished_at' => now()];
        $this->assertRejectedBy('reset_runs_result_reloaded_at_check', fn () => $this->insert($succeeded));

        $this->insert(['result' => 'succeeded', 'finished_at' => now(), 'reloaded_at' => now()]);
        $this->insert(['result' => 'failed', 'finished_at' => now()]);
        $this->assertSame(2, DB::table('reset_runs')->count());
    }

    public function test_a_decided_row_has_its_end_and_an_open_row_has_none(): void
    {
        $check = 'reset_runs_result_finished_at_check';
        $this->assertRejectedBy($check, fn () => $this->insert(['result' => 'failed']));
        $this->assertRejectedBy($check, fn () => $this->insert(['finished_at' => now()]));

        $this->insert([]);
        $this->assertSame(1, DB::table('reset_runs')->count());
    }

    public function test_the_trigger_and_the_result_are_listed_values(): void
    {
        $this->assertRejectedBy('reset_runs_trigger_check', fn () => $this->insert(['trigger' => 'other']));
        $running = ['result' => 'running', 'finished_at' => now()];
        $this->assertRejectedBy('reset_runs_result_check', fn () => $this->insert($running));
    }

    public function test_a_reservation_counts_until_retry_after_has_passed(): void
    {
        config(['queue.default' => 'database']);
        $this->travelTo(now());
        $insert = fn (int $secondsAgo) => DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 1,
            'reserved_at' => now()->getTimestamp() - $secondsAgo, 'available_at' => 0, 'created_at' => 0,
        ]);
        $insert(3600);
        $insert(3601);
        $waiting = ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0];
        DB::table('jobs')->insert($waiting);
        $this->assertSame(0, app(ResetTables::class)->processingJobs());

        $insert(3599);
        $this->assertSame(1, app(ResetTables::class)->processingJobs());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function insert(array $values): void
    {
        $row = ['reset_on' => '2026-10-18', 'trigger' => 'manual', 'started_at' => now()];
        DB::table('reset_runs')->insert([...$row, ...$values]);
    }
}
