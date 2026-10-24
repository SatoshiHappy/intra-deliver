<?php

namespace Tests\Feature\Reset;

use App\Actions\Reset\ResetStops;
use App\Enums\Reset\ResetResult;
use App\Enums\Reset\ResetTrigger;
use App\Models\Reset\ResetRun;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/**
 * 初期化のテストの準備: キューは DB の接続(処理中のジョブを数える表)、ファイルは偽の置き場、待ちは偽の Sleep(Carbon の時計を進める)、
 * 時刻は東京の 2026-10-18 09:00(その日 = 10-18)。既定の seeder は軽い {@see StubInitialSeeder} に差し替える(本物の DatabaseSeeder で流すのは
 * {@see ResetSucceedsTest} だけ)。ほかの接続が持つ鎖錠は {@see self::holdLockElsewhere()} で取り、終わりに接続ごと閉じる。
 */
trait PreparesReset
{
    protected const OTHER_CONNECTION = 'reset_other';

    protected function prepareReset(bool $stubSeeder = true): void
    {
        config(['queue.default' => 'database']);
        Storage::fake((string) config('reset.disk'));
        $this->travelTo(CarbonImmutable::parse('2026-10-18 09:00', 'Asia/Tokyo'));
        Sleep::fake(syncWithCarbon: true);
        StubInitialSeeder::$calls = [];

        if ($stubSeeder) {
            $this->app->bind(DatabaseSeeder::class, StubInitialSeeder::class);
        }

        $this->beforeApplicationDestroyed(function (): void {
            DB::purge(self::OTHER_CONNECTION);
        });
    }

    /** 別のセッションが初期化の鎖錠を持つ(動いている回がある) */
    protected function holdLockElsewhere(): void
    {
        $default = config('database.connections.'.config('database.default'));
        config(['database.connections.'.self::OTHER_CONNECTION => $default]);
        DB::connection(self::OTHER_CONNECTION)->select('SELECT pg_advisory_lock(?)', [config('reset.lock_key')]);
    }

    protected function releaseLockElsewhere(): void
    {
        DB::connection(self::OTHER_CONNECTION)->select('SELECT pg_advisory_unlock(?)', [config('reset.lock_key')]);
    }

    /** このセッションの鎖錠を放す(接続が切れて鎖錠を失ったのと同じ) */
    protected function loseLock(): void
    {
        DB::select('SELECT pg_advisory_unlock_all()');
    }

    /** 落ちた回の残りを作る */
    protected function leaveCrashedRun(bool $maintenance = true, bool $row = true): ?ResetRun
    {
        $attributes = ['reset_on' => '2026-10-17', 'trigger' => ResetTrigger::Schedule, 'started_at' => now()];
        $run = $row ? ResetRun::query()->create($attributes) : null;
        Queue::pauseFor('database', 'default', 3600);

        if ($maintenance) {
            App::maintenanceMode()->activate(['status' => 503, ResetStops::MARK => $run->id ?? 1]);
        }

        return $run;
    }

    protected function tried(string $day, ResetResult $result = ResetResult::Succeeded): void
    {
        ResetRun::query()->create([
            'reset_on' => $day, 'trigger' => ResetTrigger::Schedule, 'started_at' => now(), 'reloaded_at' => now(),
            'result' => $result, 'finished_at' => now(),
        ]);
    }

    protected function queuePaused(): bool
    {
        return Queue::isPaused('database', 'default');
    }

    /** 予約から $secondsAgo 秒たったジョブ(処理中) */
    protected function reservedJob(int $secondsAgo): int
    {
        $reservedAt = now()->getTimestamp() - $secondsAgo;

        return DB::table('jobs')->insertGetId([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => $reservedAt,
            'available_at' => $reservedAt, 'created_at' => $reservedAt,
        ]);
    }
}
