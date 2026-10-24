<?php

namespace Tests\Feature\Reset;

use App\Actions\Reset\Api\ResetParticipant;
use App\Models\Reset\ResetRun;
use App\Queries\Reset\ResetSettings;
use App\Queries\Reset\ResetTables;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TransactionalTestCase;

/**
 * ほかのモジュールとの約束の 1〜3 項め(docs の 設計/公開の配備/毎日の初期化.md 1 節。4 項めは、本物の初期データで流す {@see ResetSucceedsTest} が確かめる)。
 */
final class ResetContractsTest extends TransactionalTestCase
{
    /** 1 項め。テストのトランザクションの中で表を消して流し直す */
    public function test_migrations_insert_no_rows(): void
    {
        Schema::dropAllTables();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $filled = array_filter(
            array_diff(app(ResetTables::class)->all(), [app(ResetSettings::class)->migrationsTable()]),
            fn (string $table): bool => DB::table($table)->exists(),
        );

        $this->assertNotEmpty(app(ResetTables::class)->all());
        $this->assertSame([], array_values($filled));
    }

    /** 2 項め。OWNED BY か identity かで見る */
    public function test_every_sequence_belongs_to_a_column(): void
    {
        $this->assertSame([], $this->orphanSequences());

        DB::statement('CREATE SEQUENCE reset_contracts_orphan_seq');
        $this->assertSame(['reset_contracts_orphan_seq'], $this->orphanSequences());
    }

    /** 3 項め */
    public function test_kept_tables_do_not_refer_to_emptied_tables(): void
    {
        $kept = [app(ResetSettings::class)->migrationsTable(), (new ResetRun)->getTable()];

        foreach ($this->app->tagged(ResetParticipant::TAG) as $participant) {
            $kept = [...$kept, ...$participant->keptTables()];
        }

        $this->assertContains('ai_spending_carryovers', $kept);
        $this->assertSame([], $this->foreignKeysOutOf($kept));
        $this->assertContains('ai_runs_requester_user_id_foreign', $this->foreignKeysOutOf([...$kept, 'ai_runs']));
    }

    /**
     * 属する列の無い連番の名前。
     *
     * @return list<mixed>
     */
    private function orphanSequences(): array
    {
        return array_values(DB::table('pg_class as c')
            ->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
            ->where('c.relkind', 'S')->where('n.nspname', DB::raw('current_schema()'))
            ->whereNotExists(fn ($query) => $query->from('pg_depend as d')
                ->whereColumn('d.objid', 'c.oid')->whereIn('d.deptype', ['a', 'i'])
                ->where('d.classid', DB::raw("'pg_class'::regclass"))
                ->where('d.refclassid', DB::raw("'pg_class'::regclass")))
            ->pluck('c.relname')->all());
    }

    /**
     * $tables の表から、$tables の外の表を指す外部キーの名前。
     *
     * @param  array<string>  $tables
     * @return list<mixed>
     */
    private function foreignKeysOutOf(array $tables): array
    {
        return array_values(DB::table('pg_constraint as c')
            ->where('c.contype', 'f')
            ->whereIn(DB::raw('c.conrelid::regclass::text'), $tables)
            ->whereNotIn(DB::raw('c.confrelid::regclass::text'), $tables)
            ->pluck('c.conname')->all());
    }
}
