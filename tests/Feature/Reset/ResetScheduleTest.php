<?php

namespace Tests\Feature\Reset;

use App\Providers\AppServiceProvider;
use App\Queries\Reset\ResetSettings;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 設定がオンの配備の予定と、起動の時の置き場の確かめ(docs の 設計/公開の配備/毎日の初期化.md 5 節・2 節)。
 */
final class ResetScheduleTest extends TransactionalTestCase
{
    use EnablesDailyResetBeforeBoot;
    use RunsCommands;

    public function test_the_watch_and_the_reset_run_every_minute_even_in_maintenance(): void
    {
        $this->assertCommand('schedule:list', 0, 'reset:daily');

        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'reset:'),
        ));

        $this->assertSame(
            [
                ['reset:watch', '* * * * *', true, '/proc/1/fd/2', true],
                ['reset:daily', '* * * * *', true, '/proc/1/fd/2', true],
            ],
            array_map(fn (Event $event): array => [
                substr((string) $event->command, (int) strrpos((string) $event->command, ' ') + 1),
                $event->expression, $event->evenInMaintenanceMode, $event->output, $event->shouldAppendOutput,
            ], $events),
        );
    }

    public function test_the_shared_stores_pass_the_boot_check(): void
    {
        (new AppServiceProvider($this->app))->boot();

        $stores = [
            config('app.maintenance.driver'), config('app.maintenance.store'),
            config('cache.default'), config('queue.default'), config('session.driver'),
        ];
        $this->assertSame(['cache', 'database', 'database', 'database', 'database'], $stores);
    }

    /**
     * 接続の設定が空(null)か既定の接続の名前で、失敗した仕事の記録の driver が DB の表のもの(database-uuids か database)なら通る
     */
    #[TestWith(['database-uuids'])]
    #[TestWith(['database'])]
    public function test_the_boot_passes_when_the_connections_are_the_default_one(string $failedDriver): void
    {
        $default = config('database.default');
        $this->assertIsString($default);
        config([
            'cache.stores.database.connection' => null, 'cache.stores.database.lock_connection' => $default,
            'queue.connections.database.connection' => $default, 'queue.failed.database' => $default,
            'session.connection' => null, 'queue.failed.driver' => $failedDriver,
        ]);

        app(ResetSettings::class)->ensureSharedStores();

        $this->addToAssertionCount(1);
    }

    /**
     * @param  array<string, string>  $settings
     */
    #[TestWith([['app.maintenance.driver' => 'file']])]
    #[TestWith([['app.maintenance.store' => 'array']])]
    #[TestWith([['cache.default' => 'array', 'app.maintenance.store' => 'array']])]
    #[TestWith([['queue.default' => 'sync']])]
    public function test_the_boot_stops_when_a_store_is_not_the_database_cache(array $settings): void
    {
        config($settings);

        $this->expectException(LogicException::class);

        (new AppServiceProvider($this->app))->boot();
    }

    /**
     * セッションの置き場が DB の表でないか、キャッシュ・鎖錠・キュー・失敗した仕事の記録・セッションの表が既定でない接続にあれば、起動が止まる(5 節)。
     *
     * @param  array<string, string>  $settings
     */
    #[TestWith([['session.driver' => 'array']])]
    #[TestWith([['session.driver' => 'file']])]
    #[TestWith([['session.connection' => 'other']])]
    #[TestWith([['cache.stores.database.connection' => 'other']])]
    #[TestWith([['cache.stores.database.lock_connection' => 'other']])]
    #[TestWith([['queue.connections.database.connection' => 'other']])]
    #[TestWith([['queue.failed.database' => 'other']])]
    public function test_the_boot_stops_when_the_session_or_a_connection_is_not_the_default(array $settings): void
    {
        config($settings);

        $this->expectException(LogicException::class);

        (new AppServiceProvider($this->app))->boot();
    }

    /**
     * 失敗した仕事の記録の置き場が DB の表でなければ、起動が止まる(5 節)。
     *
     * @param  array<string, string|null>  $settings
     */
    #[TestWith([['queue.failed.driver' => 'file']])]
    #[TestWith([['queue.failed.driver' => 'null']])]
    #[TestWith([['queue.failed.driver' => 'dynamodb']])]
    #[TestWith([['queue.failed.driver' => 'database', 'queue.failed.table' => null]])]
    public function test_the_boot_stops_when_failed_jobs_are_not_kept_in_a_table(array $settings): void
    {
        config($settings);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('QUEUE_FAILED_DRIVER');

        (new AppServiceProvider($this->app))->boot();
    }

    public function test_the_cache_tables_are_the_table_and_the_lock_table_of_the_default_store(): void
    {
        $this->assertSame(['cache', 'cache_locks'], app(ResetSettings::class)->cacheTables());

        config(['cache.stores.database.table' => 'other_cache', 'cache.stores.database.lock_table' => 'other_locks']);
        $this->assertSame(['other_cache', 'other_locks'], app(ResetSettings::class)->cacheTables());
    }
}
