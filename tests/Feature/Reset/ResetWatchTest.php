<?php

namespace Tests\Feature\Reset;

use App\Enums\Reset\ResetResult;
use App\Models\Reset\ResetRun;
use Illuminate\Support\Facades\App;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 見張りの表(docs の 設計/公開の配備/毎日の初期化.md 4 節の 5 行)。
 */
final class ResetWatchTest extends TransactionalTestCase
{
    use EnablesDailyResetBeforeBoot;
    use PreparesReset;
    use RunsCommands;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareReset();
    }

    /** 見張りの表の「取れない」の行(初期化の印のメンテナンスの状態 × 記録の終わりの無い行のどの組み合わせでも何もしない) */
    #[TestWith([true, true])]
    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([false, false])]
    public function test_does_nothing_while_a_run_holds_the_lock(bool $maintenance, bool $row): void
    {
        $run = $this->leaveCrashedRun($maintenance, $row);
        $this->holdLockElsewhere();

        $this->assertStringNotContainsString('片付けた', $this->assertCommand('reset:watch', 0));

        $this->assertNull($run?->refresh()->result);
        $this->assertSame([$maintenance, true], [App::maintenanceMode()->active(), $this->queuePaused()]);
    }

    public function test_does_nothing_without_leftovers(): void
    {
        $this->tried('2026-10-18');

        $this->assertStringNotContainsString('片付けた', $this->assertCommand('reset:watch', 0));

        $this->assertFalse(App::maintenanceMode()->active());
    }

    /** 見張りの表の「取れる・無い・無い」の行(キューの停止だけが残っている。行が無くても解く。出さない) */
    public function test_resumes_the_queue_left_paused_without_a_row_or_maintenance(): void
    {
        $this->leaveCrashedRun(maintenance: false, row: false);

        $this->assertStringNotContainsString('片付けた', $this->assertCommand('reset:watch', 0));

        $this->assertSame([false, false], [App::maintenanceMode()->active(), $this->queuePaused()]);
    }

    /** 見張りの表の「取れる・ある・無い」の行(行が無くてもキューの停止を解く) */
    public function test_lifts_the_marked_maintenance_and_the_queue_left_without_a_row(): void
    {
        $this->leaveCrashedRun(row: false);

        $this->assertCommand('reset:watch', 0, 'メンテナンスの状態を解いた');

        $this->assertSame([false, false], [App::maintenanceMode()->active(), $this->queuePaused()]);
    }

    /** 見張りの表の「取れる・無い・ある」の行 */
    public function test_closes_a_row_left_open_and_resumes_the_queue(): void
    {
        $run = $this->leaveCrashedRun(maintenance: false);

        $this->assertCommand('reset:watch', 0, "失敗で閉じた行 = {$run?->id}");

        $this->assertClosed($run);
        $this->assertFalse($this->queuePaused());
    }

    public function test_lifts_both_and_closes_the_row(): void
    {
        $run = $this->leaveCrashedRun();

        $this->assertCommand('reset:watch', 0);

        $this->assertClosed($run);
        $this->assertSame([false, false], [App::maintenanceMode()->active(), $this->queuePaused()]);
    }

    /** 印の無いメンテナンスの状態(運営者が手で入れたもの)は、どの行でも解かない。キューの停止は解く */
    #[TestWith([true])]
    #[TestWith([false])]
    public function test_keeps_the_maintenance_put_by_the_operator(bool $row): void
    {
        $run = $this->leaveCrashedRun(maintenance: false, row: $row);
        App::maintenanceMode()->activate(['status' => 503]);

        $this->assertCommand('reset:watch', 0);

        $row ? $this->assertClosed($run) : $this->assertNull($run);
        $this->assertSame([true, false], [App::maintenanceMode()->active(), $this->queuePaused()]);
    }

    private function assertClosed(?ResetRun $run): void
    {
        $this->assertSame(ResetResult::Failed, $run?->refresh()->result);
        $this->assertNotNull($run->finished_at);
    }
}
