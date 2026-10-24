<?php

namespace Tests\Feature\Reset;

use App\Enums\Reset\ResetResult;
use App\Enums\Reset\ResetTrigger;
use App\Models\Reset\ResetRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 「始めるか」の表(docs の 設計/公開の配備/毎日の初期化.md 2 節。オンの行。オフの行は {@see ResetDisabledTest})と、その日の境目、
 * 手順 0 の片付け、鎖錠の待ち。予定の回 = reset:daily、手で流す回 = reset:data。
 */
final class ResetStartTest extends TransactionalTestCase
{
    use EnablesDailyResetBeforeBoot;
    use PreparesReset;
    use RunsCommands;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareReset();
    }

    #[TestWith(['reset:daily', 0])]
    #[TestWith(['reset:data', 1])]
    public function test_does_not_start_while_another_run_holds_the_lock(string $command, int $exitCode): void
    {
        $this->holdLockElsewhere();

        $this->assertCommand($command, $exitCode, 'ほかの初期化が動いている');

        $this->assertSame(0, ResetRun::query()->count());
        Sleep::assertSleptTimes((int) config('reset.lock_wait_seconds'));
    }

    #[TestWith(['reset:daily', 0])]
    #[TestWith(['reset:data', 1])]
    public function test_does_not_start_under_maintenance_put_by_the_operator(string $command, int $exitCode): void
    {
        App::maintenanceMode()->activate(['status' => 503]);

        $this->assertCommand($command, $exitCode, '初期化の印の無いメンテナンスの状態');

        $this->assertSame(0, ResetRun::query()->count());
        $this->assertTrue(App::maintenanceMode()->active());
    }

    /** 「始めるか」の表の予定・試し済みの行。毎分動くので出力は空(出力の列) */
    public function test_the_scheduled_run_does_nothing_and_prints_nothing_once_the_day_was_tried(): void
    {
        $this->tried('2026-10-18', ResetResult::Failed);

        $this->assertSame('', trim($this->assertCommand('reset:daily', 0)));

        $this->assertSame(1, ResetRun::query()->count());
    }

    /** 出力の列: 始めた回は記録の行を書いた時に番号を出し、終わりに結果を出す(番号は 1 回だけ) */
    #[TestWith(['reset:daily'])]
    #[TestWith(['reset:data'])]
    public function test_a_started_run_prints_the_row_number_once_before_the_result(string $command): void
    {
        $output = $this->assertCommand($command, 0);

        $run = ResetRun::query()->sole();
        $this->assertSame(1, substr_count($output, '初期化の記録の行'));
        $this->assertSame(0, strpos($output, "初期化の記録の行: {$run->id}\n"));
        $this->assertLessThan(strpos($output, '成否: 成功'), strpos($output, '初期化の記録の行'));
    }

    /** 手順 0 の片付け: 記録の行が無くても、キューの停止を解いてから決める */
    public function test_cleans_up_a_queue_pause_left_without_a_row(): void
    {
        $this->tried('2026-10-18');
        $this->leaveCrashedRun(maintenance: false, row: false);

        $this->assertSame('', trim($this->assertCommand('reset:daily', 0)));

        $this->assertFalse($this->queuePaused());
    }

    public function test_the_scheduled_run_starts_when_the_day_was_not_tried(): void
    {
        $this->tried('2026-10-17');

        $this->assertCommand('reset:daily', 0);

        $this->assertStarted(ResetTrigger::Schedule, '2026-10-18');
    }

    public function test_a_manual_run_starts_even_when_the_day_was_tried(): void
    {
        $this->tried('2026-10-18');

        $this->assertCommand('reset:data', 0, '成否: 成功');

        $this->assertStarted(ResetTrigger::Manual, '2026-10-18');
    }

    /**
     * その日の境目(2 節)。東京の 3:59・4:00・4:01 を通す。
     */
    #[TestWith(['03:59', false])]
    #[TestWith(['04:00', true])]
    #[TestWith(['04:01', true])]
    public function test_the_day_starts_at_four_in_tokyo(string $time, bool $starts): void
    {
        $this->travelTo(CarbonImmutable::parse("2026-10-18 {$time}", 'Asia/Tokyo'));
        $this->tried('2026-10-17');

        $this->assertCommand('reset:daily', 0);

        $this->assertSame($starts, ResetRun::query()->where('reset_on', '2026-10-18')->exists());
        $this->assertSame($starts ? 2 : 1, ResetRun::query()->count());
    }

    /** 4:00 に動けなかった日(2 節) */
    public function test_the_first_minute_after_a_stopped_morning_starts_the_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-18 10:31', 'Asia/Tokyo'));
        $this->tried('2026-10-16');
        $this->tried('2026-10-17');

        $this->assertCommand('reset:daily', 0);
        $this->assertSame('', trim($this->assertCommand('reset:daily', 0)));

        $this->assertSame(1, ResetRun::query()->where('reset_on', '2026-10-18')->count());
    }

    /** 手順 0 の片付け */
    public function test_cleans_up_a_crashed_run_before_deciding(): void
    {
        $crashed = $this->leaveCrashedRun();

        $this->assertCommand('reset:data', 0);

        $this->assertSame(ResetResult::Failed, $crashed?->refresh()->result);
        $this->assertNotNull($crashed->finished_at);
        $this->assertFalse(App::maintenanceMode()->active());
        $this->assertFalse($this->queuePaused());
        $this->assertStarted(ResetTrigger::Manual, '2026-10-18');
    }

    /** 手順 0 の鎖錠の待ち */
    public function test_waits_a_short_while_for_the_lock(): void
    {
        $this->holdLockElsewhere();
        Sleep::whenFakingSleep(function (): void {
            if (ResetRun::query()->doesntExist()) {
                $this->releaseLockElsewhere();
            }
        });

        $this->assertCommand('reset:data', 0);

        $this->assertStarted(ResetTrigger::Manual, '2026-10-18');
    }

    private function assertStarted(ResetTrigger $trigger, string $day): void
    {
        $run = ResetRun::query()->latest('id')->firstOrFail();

        $actual = [$run->trigger, $run->reset_on->toDateString(), $run->result];
        $this->assertSame([$trigger, $day, ResetResult::Succeeded], $actual);
        $this->assertSame([StubInitialSeeder::class], StubInitialSeeder::$calls);
    }
}
