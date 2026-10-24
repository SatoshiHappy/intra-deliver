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
            config('cache.default'), config('queue.default'),
        ];
        $this->assertSame(['cache', 'database', 'database', 'database'], $stores);
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

    public function test_the_cache_tables_are_the_table_and_the_lock_table_of_the_default_store(): void
    {
        $this->assertSame(['cache', 'cache_locks'], app(ResetSettings::class)->cacheTables());

        config(['cache.stores.database.table' => 'other_cache', 'cache.stores.database.lock_table' => 'other_locks']);
        $this->assertSame(['other_cache', 'other_locks'], app(ResetSettings::class)->cacheTables());
    }
}
