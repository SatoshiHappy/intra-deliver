<?php

namespace Tests\Feature\Demo;

use App\Actions\Reset\Api\ResetParticipant;
use App\Models\Reset\ResetRun;
use App\Queries\Reset\Api\ResetSeeder;
use App\Queries\Reset\ResetTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Reset\EnablesDailyResetBeforeBoot;
use Tests\Feature\Reset\PreparesReset;
use Tests\Feature\Reset\ResetContractsTest;
use Tests\Feature\Reset\RunsCommands;
use Tests\Feature\Reset\TestResetSeeder;
use Tests\Feature\Reset\WritesFileSeeder;
use Tests\Support\TransactionalTestCase;

/**
 * ほかのモジュールとの約束の 4・5 項め(docs の 設計/公開の配備/毎日の初期化.md 1 節)を、デモユーザー切替がオンの並びの本物の初期データで確かめる
 * (1〜3 項めは {@see ResetContractsTest})。オンの並び = DatabaseSeeder → DemoSeeder(→ このクラスの seeder)。
 */
final class ResetContractsWithDemoTest extends TransactionalTestCase
{
    use EnablesDailyResetBeforeBoot, EnablesDemoBeforeBoot {
        EnablesDailyResetBeforeBoot::environmentOverrides as private resetOverrides;
        EnablesDemoBeforeBoot::environmentOverrides as private demoOverrides;
    }
    use PreparesReset;
    use RunsCommands;

    /**
     * @return array<string, string>
     */
    protected function environmentOverrides(): array
    {
        return [...$this->resetOverrides(), ...$this->demoOverrides()];
    }

    /** 4 項め。2 回流して、2 回目の後のファイルの数が 1 回目の後と同じで、名前が 1 つも重ならない(前の日のファイルが残らず、消えもしない) */
    public function test_files_written_by_the_seeders_get_new_names_each_run(): void
    {
        $this->prepareReset(stubSeeder: false);
        $this->assertDemoSeederIsInTheSequence();
        $this->app->instance(TestResetSeeder::class, new TestResetSeeder(WritesFileSeeder::class));
        $this->app->tag([TestResetSeeder::class], ResetSeeder::TAG);
        $disk = Storage::disk((string) config('reset.disk'));

        $this->assertCommand('reset:data', 0, '成否: 成功');
        $first = $disk->allFiles();
        $this->assertCommand('reset:data', 0, '成否: 成功');
        $second = $disk->allFiles();

        $this->assertNotEmpty($first);
        $this->assertCount(count($first), $second);
        $this->assertSame([], array_intersect($first, $second));
        $this->assertCount(2, WritesFileSeeder::$files);
        $this->assertSame(2, ResetRun::query()->count());
    }

    /** 5 項め。空の DB に初期データだけを入れても、残す表と、残す表へ移す元の実行の記録は空のまま */
    public function test_the_initial_data_leaves_the_kept_tables_and_the_runs_to_move_empty(): void
    {
        $this->prepareReset(stubSeeder: false);
        $this->assertDemoSeederIsInTheSequence();
        $tables = array_diff(app(ResetTables::class)->all(), ['migrations']);
        DB::statement('TRUNCATE TABLE '.implode(', ', $tables).' RESTART IDENTITY');

        $this->assertCommand('reset:seed', 0, '初期データを入れました');

        $this->assertGreaterThan(0, DB::table('users')->count());
        $kept = ['reset_runs', 'ai_runs'];

        foreach ($this->app->tagged(ResetParticipant::TAG) as $participant) {
            $kept = [...$kept, ...$participant->keptTables()];
        }

        $this->assertContains('ai_spending_carryovers', $kept);
        $filled = array_filter($kept, fn (string $table): bool => DB::table($table)->exists());
        $this->assertSame([], array_values($filled));
    }

    private function assertDemoSeederIsInTheSequence(): void
    {
        $seeders = iterator_to_array($this->app->tagged(ResetSeeder::TAG), false);
        $names = array_map(fn (ResetSeeder $seeder): string => $seeder->seederName(), $seeders);

        $this->assertSame(['DemoSeeder'], $names);
    }
}
