<?php

namespace Tests\Feature\Migrations;

use App\Models\Master\User;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TransactionalTestCase;

/**
 * 通知の受け取りの設定の表 notice_opt_outs をミュートの印の表 notice_mutes に改名するマイグレーションの down と、その後の up
 * (docs の ADR 0050 の行の移行。制約・索引・連番の名前も共通の規則 9 節の形に揃え、行と連番は残す)。
 * DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class NoticeMutesRenameMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_11_18_100001_rename_notice_opt_outs_to_notice_mutes.php';

    public function test_up_names_the_table_and_its_constraints_indexes_and_sequence_after_notice_mutes(): void
    {
        $this->assertFalse(Schema::hasTable('notice_opt_outs'));
        $this->assertSame(self::namesOf('notice_mutes'), $this->constraintIndexAndSequenceNames('notice_mutes'));
    }

    public function test_down_and_up_keep_the_rows_and_the_sequence(): void
    {
        $user = User::factory()->createOne();
        $id = DB::table('notice_mutes')->insertGetId(['user_id' => $user->id, 'type' => 'workflow.result']);

        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertFalse(Schema::hasTable('notice_mutes'));
        $this->assertSame(self::namesOf('notice_opt_outs'), $this->constraintIndexAndSequenceNames('notice_opt_outs'));
        $this->assertSame([$id], $this->idsOf('notice_opt_outs'));

        $this->migrator()->run([$this->migrationPath()]);

        $this->assertSame(self::namesOf('notice_mutes'), $this->constraintIndexAndSequenceNames('notice_mutes'));
        $this->assertSame([$id], $this->idsOf('notice_mutes'));
        $next = DB::table('notice_mutes')->insertGetId(['user_id' => $user->id, 'type' => 'workflow.proxy']);
        $this->assertGreaterThan($id, $next);
    }

    /**
     * 表の名前の形の名前(主キー・一意・外部キー・検査・NOT NULL の制約と、同じ名前の索引と連番)。
     *
     * @return list<string>
     */
    private static function namesOf(string $table): array
    {
        $names = array_map(fn (string $suffix): string => "{$table}_{$suffix}", [
            'id_not_null', 'id_seq', 'pkey', 'type_check', 'type_not_null', 'user_id_foreign', 'user_id_not_null',
            'user_id_type_unique',
        ]);
        sort($names);

        return $names;
    }

    /**
     * 表の制約(NOT NULL を含む)・索引・連番の名前(重ねず、名前の順)。
     *
     * @return list<string>
     */
    private function constraintIndexAndSequenceNames(string $table): array
    {
        $constraints = DB::table('pg_constraint')->whereRaw('conrelid = ?::regclass', [$table])
            ->pluck('conname')->all();
        $indexes = DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))
            ->where('tablename', $table)->pluck('indexname')->all();
        $sequence = DB::selectOne('select pg_get_serial_sequence(?, ?) as name', [$table, 'id'])->name;
        $names = array_values(array_unique([...$constraints, ...$indexes, explode('.', (string) $sequence)[1] ?? '']));
        sort($names);

        return $names;
    }

    /**
     * @return list<int>
     */
    private function idsOf(string $table): array
    {
        return array_values(array_map('intval', DB::table($table)->orderBy('id')->pluck('id')->all()));
    }

    /**
     * Laravel のマイグレーターで、このマイグレーションだけを down・up する(パスに渡したファイルのマイグレーションだけが対象になる)。
     */
    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function migrationPath(): string
    {
        return database_path('migrations/'.self::MIGRATION);
    }
}
