<?php

namespace Tests\Feature\Reset;

use App\Enums\Reset\ResetResult;
use App\Models\Reset\ResetRun;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Tests\Support\TransactionalTestCase;

/**
 * 処理中のジョブ(docs の 設計/公開の配備/毎日の初期化.md 2 節の手順 2・4 と「処理中のジョブ」、4 節の「待ちが最長を過ぎた」)。
 * 待ちは偽の Sleep(Carbon の時計を進める)。
 */
final class ResetJobsWaitTest extends TransactionalTestCase
{
    use EnablesDailyResetBeforeBoot;
    use PreparesReset;
    use RunsCommands;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareReset();
    }

    /** 手順 2 の待ち(3 回目の確かめの後にジョブが終わる = 15 秒) */
    public function test_waits_for_a_job_reserved_within_retry_after(): void
    {
        $job = $this->reservedJob(60);
        $polls = 0;
        Sleep::whenFakingSleep(function () use ($job, &$polls): void {
            if (++$polls === 4) {
                DB::table('jobs')->where('id', $job)->delete();
            }
        });

        $this->assertCommand('reset:data', 0, '手順 2 で待ったジョブ: 1 件・15 秒');

        $this->assertSame(ResetResult::Succeeded, ResetRun::query()->sole()->result);
    }

    /** 「処理中のジョブ」の項(予約が retry_after を過ぎたジョブ) */
    public function test_does_not_wait_for_a_reservation_past_retry_after(): void
    {
        $this->reservedJob(3600);

        $this->assertCommand('reset:data', 0, '手順 2 で待ったジョブ: 0 件・0 秒');

        $this->assertSame(ResetResult::Succeeded, ResetRun::query()->sole()->result);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /** 4 節の「待ちが最長を過ぎた」の項 */
    public function test_moves_on_when_the_wait_passes_retry_after(): void
    {
        config(['queue.connections.database.retry_after' => 60]);
        $job = $this->reservedJob(0);
        Sleep::whenFakingSleep(function () use ($job): void {
            $reservedAt = App::maintenanceMode()->active() ? null : now()->getTimestamp();
            DB::table('jobs')->where('id', $job)->update(['reserved_at' => $reservedAt]);
        });

        $this->assertCommand('reset:data', 0, '手順 2 で待ったジョブ: 1 件・60 秒');

        $this->assertSame(ResetResult::Succeeded, ResetRun::query()->sole()->result);
    }
}
