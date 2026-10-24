<?php

namespace Tests\Feature\Reset;

use App\Actions\Reset\Api\ResetParticipant;
use App\Actions\Reset\ResetFailed;
use App\Actions\Reset\ResetLockLost;
use App\Actions\Reset\ResetStops;
use App\Actions\Reset\ResetToInitialData;
use App\Enums\Reset\ResetResult;
use App\Enums\Reset\ResetTrigger;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiSpendingCarryover;
use App\Models\Reset\ResetRun;
use App\Queries\Reset\Api\ResetSeeder;
use App\Queries\Reset\ResetSettings;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 失敗したときと落ちたときの表(docs の 設計/公開の配備/毎日の初期化.md 4 節の 6 行)。
 * 前の日のデータの印 = 失敗したジョブの表の 1 行(初期データの {@see StubInitialSeeder} の行と別)。
 */
final class ResetFailureTest extends TransactionalTestCase
{
    use EnablesDailyResetBeforeBoot;
    use PreparesReset;
    use RunsCommands;

    private const PREVIOUS_DAY = '前の日のデータ';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareReset();
        TestParticipant::$before = null;
        $this->app->tag([TestParticipant::class], ResetParticipant::TAG);
        DB::table('failed_jobs')->insert([
            'uuid' => 'previous-day', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}',
            'exception' => self::PREVIOUS_DAY, 'failed_at' => now(),
        ]);
        AiRun::factory()->succeeded('1.000000')->create(['created_at' => now()]);
    }

    /** 4 節の失敗の表の「手順 0 で行を書いた後〜手順 4・持ったまま例外」の行(手順 4 で処理中のジョブがある) */
    public function test_a_failure_before_reloading_lifts_the_stops_and_closes_the_row(): void
    {
        Sleep::whenFakingSleep(function (): void {
            if (App::maintenanceMode()->active() && DB::table('jobs')->doesntExist()) {
                $this->reservedJob(1);
            }
        });

        $output = $this->assertCommand('reset:data', 1, '処理中のジョブが 1 件');

        $this->assertStringContainsString('初期化の記録の行: '.ResetRun::query()->sole()->id, $output);
        $this->assertClosedWithoutReloading();
    }

    /** 4 節の失敗の表の「手順 5 の途中・持ったまま例外」の行(seeder の失敗)。予定の回も手で流す回も、終了コードは 0 でない */
    #[TestWith(['reset:data'])]
    #[TestWith(['reset:daily'])]
    public function test_a_seeder_failure_rolls_everything_back(string $command): void
    {
        $this->app->instance(TestResetSeeder::class, new TestResetSeeder(ThrowingSeeder::class));
        $this->app->tag([TestResetSeeder::class], ResetSeeder::TAG);

        $output = $this->assertCommand($command, 1, 'seeder の失敗');

        $this->assertSame(1, substr_count($output, '初期化の記録の行'));
        $this->assertStringContainsString('初期化の記録の行: '.ResetRun::query()->sole()->id, $output);
        $this->assertStringContainsString('成否: 失敗', $output);

        $this->assertSame([StubInitialSeeder::class, ThrowingSeeder::class], StubInitialSeeder::$calls);
        $this->assertFalse(DB::table('failed_jobs')->where('uuid', 'written-before-failing')->exists());
        $this->assertClosedWithoutReloading();
    }

    /** 4 節の失敗の表の「手順 5 の途中・持ったまま例外」の行(口の空にする直前の処理の例外) */
    public function test_an_exception_before_truncating_rolls_everything_back(): void
    {
        TestParticipant::$before = fn () => throw new RuntimeException('空にする直前の処理の失敗');

        $this->assertCommand('reset:data', 1, '空にする直前の処理の失敗');

        $this->assertSame([], StubInitialSeeder::$calls);
        $this->assertClosedWithoutReloading();
    }

    /** 4 節の失敗の表の「手順 6・7・持ったまま例外」の行(控えたファイルを消せない) */
    public function test_a_failure_after_the_commit_keeps_the_reloaded_data_and_the_mark(): void
    {
        $disk = Storage::disk((string) config('reset.disk'));
        $disk->put('private/uploads/locked/kept.txt', '消せない');
        $directory = $disk->path('private/uploads/locked');
        chmod($directory, 0555);

        try {
            $this->assertCommand('reset:data', 1, '消せなかった');
        } finally {
            chmod($directory, 0755);
        }

        $run = ResetRun::query()->sole();
        $this->assertSame(ResetResult::Failed, $run->result);
        $this->assertNotNull($run->reloaded_at);
        $this->assertReloaded();
        $this->assertStopsLifted();
    }

    /** 4 節の失敗の表の「手順 0 で行を書いた後〜手順 4・失った」の行 */
    public function test_a_run_that_lost_the_lock_before_reloading_leaves_the_rest_to_the_watch(): void
    {
        Sleep::whenFakingSleep(function (): void {
            if (App::maintenanceMode()->active()) {
                $this->loseLock();
            }
        });

        $this->assertCommand('reset:data', 1, '鎖錠を失っていた');

        $this->assertLeftToTheWatch(reloaded: false, maintenance: true);
    }

    /** 4 節の失敗の表の「手順 5 の途中・失った」の行 */
    public function test_a_run_that_lost_the_lock_while_reloading_leaves_the_rest_to_the_watch(): void
    {
        TestParticipant::$before = function (): void {
            $this->loseLock();

            throw new RuntimeException('接続が切れた');
        };

        $this->assertCommand('reset:data', 1);

        $this->assertLeftToTheWatch(reloaded: false, maintenance: true);
    }

    /** 4 節の失敗の表の「手順 6・7・失った」の行 */
    public function test_a_run_that_lost_the_lock_after_the_commit_leaves_the_row_to_the_watch(): void
    {
        TestParticipant::$before = fn () => $this->loseLock();

        $this->assertCommand('reset:data', 1, '記録を閉じる前に');

        $this->assertLeftToTheWatch(reloaded: true, maintenance: false);
    }

    /** 初期データの失敗の後に、片付け(キューの停止を解く)も投げる。連鎖は 片付けの例外 → ResetFailed → 初期データの失敗 */
    public function test_a_cleanup_failure_after_a_failed_run_keeps_the_whole_chain_for_the_watch(): void
    {
        $this->useSeederFailure();
        $this->replaceStops(fn () => throw new RuntimeException('キューの停止を解けなかった'));

        $chain = $this->chainOfExecute();

        $this->assertSame(
            [RuntimeException::class, ResetFailed::class, RuntimeException::class],
            array_map(fn (Throwable $error): string => $error::class, $chain),
        );
        $this->assertSame(
            ['キューの停止を解けなかった', 'seeder の失敗', 'seeder の失敗'],
            array_map(fn (Throwable $error): string => $error->getMessage(), $chain),
        );
        $this->assertNull(ResetRun::query()->sole()->result);
        $this->assertTrue($this->queuePaused());

        $this->app->forgetInstance(ResetStops::class);
        $this->assertCommand('reset:watch', 0);

        $this->assertSame(ResetResult::Failed, ResetRun::query()->sole()->result);
        $this->assertStopsLifted();
    }

    /** 片付けで鎖錠を失うと、行を閉じる close() が ResetLockLost を投げる。連鎖は ResetLockLost → ResetFailed → 初期データの失敗 */
    public function test_a_cleanup_that_loses_the_lock_chains_the_lock_loss_to_the_failure(): void
    {
        $this->useSeederFailure();
        $this->replaceStops(fn () => $this->loseLock());

        $chain = $this->chainOfExecute();

        $this->assertSame(
            [ResetLockLost::class, ResetFailed::class, RuntimeException::class],
            array_map(fn (Throwable $error): string => $error::class, $chain),
        );
        $this->assertSame('seeder の失敗', $chain[2]->getMessage());
        $this->assertNull(ResetRun::query()->sole()->result);

        $this->app->forgetInstance(ResetStops::class);
        $this->assertCommand('reset:watch', 0);

        $this->assertSame(ResetResult::Failed, ResetRun::query()->sole()->result);
        $this->assertStopsLifted();
    }

    /** 片付けが投げた回も、記録の行を書いた時に出した番号が出力に残っている(運営者が reset-status で行を探せる) */
    public function test_the_row_number_is_printed_even_when_the_cleanup_throws(): void
    {
        $this->useSeederFailure();
        $this->replaceStops(fn () => throw new RuntimeException('キューの停止を解けなかった'));

        [$output, $error] = $this->runCommandKeepingOutput('reset:data');

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertSame('キューの停止を解けなかった', $error->getMessage());
        $this->assertSame('初期化の記録の行: '.ResetRun::query()->sole()->id."\n", $output);
    }

    private function useSeederFailure(): void
    {
        $this->app->instance(TestResetSeeder::class, new TestResetSeeder(ThrowingSeeder::class));
        $this->app->tag([TestResetSeeder::class], ResetSeeder::TAG);
    }

    /** 記録の行を書いた後のキューの停止を解く操作だけを $onResume に差し替える(手順 0 の片付けと、それ以外の停止の操作は本物) */
    private function replaceStops(callable $onResume): void
    {
        $settings = $this->app->make(ResetSettings::class);

        $this->app->instance(ResetStops::class, new class($settings, $onResume) extends ResetStops
        {
            /** @param  callable(): mixed  $onResume */
            public function __construct(ResetSettings $settings, private $onResume)
            {
                parent::__construct($settings);
            }

            public function resumeQueue(): void
            {
                ResetRun::query()->exists() ? ($this->onResume)() : parent::resumeQueue();
            }
        });
    }

    /**
     * execute() が投げた例外とその previous を、先頭から順に並べる。
     *
     * @return list<Throwable>
     */
    private function chainOfExecute(): array
    {
        try {
            $this->app->make(ResetToInitialData::class)->execute(ResetTrigger::Manual);
        } catch (Throwable $error) {
            $chain = [];

            for (; $error !== null; $error = $error->getPrevious()) {
                $chain[] = $error;
            }

            return $chain;
        }

        $this->fail('execute() が投げなかった');
    }

    private function assertClosedWithoutReloading(): void
    {
        $run = ResetRun::query()->sole();
        $this->assertSame(ResetResult::Failed, $run->result);
        $this->assertNotNull($run->finished_at);
        $this->assertNull($run->reloaded_at);
        $this->assertPreviousDay();
        $this->assertStopsLifted();
    }

    private function assertLeftToTheWatch(bool $reloaded, bool $maintenance): void
    {
        $run = ResetRun::query()->sole();
        $this->assertSame([null, null, $reloaded], [$run->result, $run->finished_at, $run->reloaded_at !== null]);
        // 停止はどちらもキャッシュの表にあり、コミットの前なら残り、後なら消えている
        $this->assertSame([$maintenance, $maintenance], [App::maintenanceMode()->active(), $this->queuePaused()]);
        $reloaded ? $this->assertReloaded() : $this->assertPreviousDay();

        $this->assertCommand('reset:watch', 0);

        $this->assertSame(ResetResult::Failed, $run->refresh()->result);
        $this->assertNotNull($run->finished_at);
        $this->assertStopsLifted();
    }

    /** 「データとファイル」の列の「前の日のまま」 */
    private function assertPreviousDay(): void
    {
        $this->assertTrue(DB::table('failed_jobs')->where('exception', self::PREVIOUS_DAY)->exists());
        $this->assertSame([1, 0], [AiRun::query()->count(), AiSpendingCarryover::query()->count()]);
    }

    /** 「データとファイル」の列の「入れ直した後」 */
    private function assertReloaded(): void
    {
        $this->assertSame([1 => '初期データ'], DB::table('failed_jobs')->pluck('exception', 'id')->all());
        $this->assertSame([0, 1], [AiRun::query()->count(), AiSpendingCarryover::query()->count()]);
    }

    private function assertStopsLifted(): void
    {
        $this->assertFalse(App::maintenanceMode()->active());
        $this->assertFalse($this->queuePaused());
    }
}
