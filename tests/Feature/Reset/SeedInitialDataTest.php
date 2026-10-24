<?php

namespace Tests\Feature\Reset;

use App\Actions\Reset\SeedInitialData;
use App\Queries\Reset\Api\ResetSeeder;
use App\Queries\Reset\ResetTables;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 初期データだけを入れるコマンド reset:seed(docs の 設計/公開の配備/毎日の初期化.md 7 節、配備と戻し方.md 6.2 の 7)。
 * 初期化のオン・オフによらないことを確かめるため、このクラスは既定のオフで起動する。
 * 空の DB = テストのトランザクションの中で、マイグレーションの記録のほかの表を空にしたもの。
 */
final class SeedInitialDataTest extends TransactionalTestCase
{
    use RunsCommands;

    protected function setUp(): void
    {
        parent::setUp();
        $tables = array_diff(app(ResetTables::class)->all(), ['migrations']);
        DB::statement('TRUNCATE TABLE '.implode(', ', $tables).' RESTART IDENTITY');
        Storage::fake((string) config('reset.disk'));
        StubInitialSeeder::$calls = [];
        $this->app->bind(DatabaseSeeder::class, StubInitialSeeder::class);
    }

    public function test_seeds_the_default_seeder_then_the_named_seeders_in_order(): void
    {
        $this->addSeeder(WritesFileSeeder::class);

        $this->assertCommand('reset:seed', 0, '初期データを入れました');

        $this->assertSame([StubInitialSeeder::class, WritesFileSeeder::class], StubInitialSeeder::$calls);
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_a_failure_on_the_way_rolls_back_and_ends_with_a_failing_code(): void
    {
        $this->addSeeder(ThrowingSeeder::class);

        $this->assertCommand('reset:seed', 1, '巻き戻しました');

        $this->assertSame([StubInitialSeeder::class, ThrowingSeeder::class], StubInitialSeeder::$calls);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_failing_exit_code_of_db_seed_is_an_exception(): void
    {
        Artisan::shouldReceive('call')->once()->andReturn(1);
        Artisan::shouldReceive('output')->andReturn('止まった');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('終了コード 1');

        app(SeedInitialData::class)->execute();
    }

    public function test_runs_without_asking_for_confirmation_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->assertCommand('reset:seed', 0);

        $this->assertSame([StubInitialSeeder::class], StubInitialSeeder::$calls);
    }

    private function addSeeder(string $name): void
    {
        $this->app->instance(TestResetSeeder::class, new TestResetSeeder($name));
        $this->app->tag([TestResetSeeder::class], ResetSeeder::TAG);
    }
}
