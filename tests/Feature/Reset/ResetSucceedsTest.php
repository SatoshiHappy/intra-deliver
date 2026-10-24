<?php

namespace Tests\Feature\Reset;

use App\Enums\Reset\ResetResult;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Models\Reset\ResetRun;
use App\Queries\Reset\Api\ResetSeeder;
use App\Queries\Reset\ResetTables;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use stdClass;
use Tests\Support\TransactionalTestCase;

/**
 * 成功の回(docs の 設計/公開の配備/毎日の初期化.md 1 節・2 節の手順 1〜7、7 節の口の表)を、本物の初期データ(DatabaseSeeder)で 1 回流して確かめる。
 * 初期データの seeder は app-storage に書かない(1 節の約束の 4 項め)ことも、ここで確かめる。
 */
final class ResetSucceedsTest extends TransactionalTestCase
{
    use EnablesDailyResetBeforeBoot;
    use PreparesReset;
    use RunsCommands;

    private const OLD_FILE = 'private/uploads/added-by-a-visitor.txt';

    public function test_a_successful_run_puts_back_the_initial_data(): void
    {
        $this->prepareReset(stubSeeder: false);
        $this->app->instance(TestResetSeeder::class, new TestResetSeeder(WritesFileSeeder::class));
        $this->app->tag([TestResetSeeder::class], ResetSeeder::TAG);
        $initial = $this->snapshot();
        $this->addVisitorData();
        $stopsWhileReloading = [];
        Sleep::whenFakingSleep(function () use (&$stopsWhileReloading): void {
            $stopsWhileReloading = [App::maintenanceMode()->active(), $this->queuePaused()];
        });

        $this->assertCommand('reset:data', 0, '成否: 成功');

        $this->assertSame([true, true], $stopsWhileReloading);
        $this->assertSame($initial, $this->snapshot());
        $this->assertSame([0, 0], [DB::table('cache')->count(), DB::table('cache_locks')->count()]);
        $this->assertSame([false, false], [App::maintenanceMode()->active(), $this->queuePaused()]);
        $this->assertSame([WritesFileSeeder::FILE], Storage::disk((string) config('reset.disk'))->allFiles());
        $this->assertSame([['default', '2026-10-17', '1.500000']], $this->carryovers());
        $run = ResetRun::query()->sole();
        $this->assertSame(ResetResult::Succeeded, $run->result);
        $this->assertNotNull($run->reloaded_at);
        $this->assertSame([WritesFileSeeder::class], StubInitialSeeder::$calls);
    }

    /** 訪問者が足すデータ(業務の行・セッション・キャッシュ・キュー・実行の記録・繰り越し・添付の実体) */
    private function addVisitorData(): void
    {
        User::factory()->count(2)->create();
        DB::table('sessions')->insert(['id' => 'visitor', 'payload' => 'x', 'last_activity' => now()->getTimestamp()]);
        Cache::put('visitor', 'x', 600);
        $job = ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => 0, 'created_at' => 0];
        DB::table('jobs')->insert($job);
        AiRun::factory()->succeeded('0.500000')->create(['created_at' => now()->subDay()]);
        $carried = ['budget' => 'default', 'spent_on' => '2026-10-17', 'cost_usd' => '1.000000'];
        DB::table('ai_spending_carryovers')->insert($carried);
        Storage::disk((string) config('reset.disk'))->put(self::OLD_FILE, '前の日');
    }

    /**
     * 残す表とキャッシュの表のほかの表ごとの行の数と、番号(id・申請の番号)。
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $kept = ['migrations', 'reset_runs', 'ai_spending_carryovers', 'cache', 'cache_locks'];
        $snapshot = [];

        foreach (array_diff(app(ResetTables::class)->all(), $kept) as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }

        $snapshot['users.id'] = DB::table('users')->orderBy('id')->pluck('id')->all();
        $cases = DB::table('workflow_cases')->orderBy('id')->pluck('case_number', 'id');
        $snapshot['workflow_cases.case_number'] = $cases->all();

        return $snapshot;
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function carryovers(): array
    {
        $rows = DB::table('ai_spending_carryovers')->orderBy('budget')->orderBy('spent_on')
            ->selectRaw('budget, spent_on::text AS spent_on, cost_usd::text AS cost_usd')->get();

        return array_values($rows->map(
            fn (stdClass $row): array => [(string) $row->budget, (string) $row->spent_on, (string) $row->cost_usd],
        )->all());
    }
}
