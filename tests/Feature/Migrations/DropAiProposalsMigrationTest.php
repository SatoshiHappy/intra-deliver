<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TransactionalTestCase;

/**
 * 提案の表を消すマイグレーション(提案の仕組みは ADR 0019 で外すと決めた): up の後に ai_proposals が無く、down は作成のマイグレーション
 * (2026_10_18_100003)と同じ列・制約・索引の表を作り直す。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class DropAiProposalsMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_19_400001_drop_ai_proposals_table.php';

    private const CREATING_MIGRATION = '2026_10_18_100003_create_ai_proposals_table.php';

    public function test_the_table_is_gone_after_up(): void
    {
        $this->assertFalse(Schema::hasTable('ai_proposals'));
    }

    public function test_down_recreates_the_table_the_creating_migration_made(): void
    {
        $this->migrator()->reset([$this->path(self::MIGRATION)]);

        $this->assertTrue(Schema::hasTable('ai_proposals'));
        $restored = $this->definition();

        Schema::drop('ai_proposals');
        (require $this->path(self::CREATING_MIGRATION))->up();

        $this->assertEquals($restored, $this->definition());
        $this->assertNotSame([], $restored['constraints']);
    }

    public function test_up_after_down_drops_the_table_again(): void
    {
        $this->migrator()->reset([$this->path(self::MIGRATION)]);
        $this->migrator()->run([$this->path(self::MIGRATION)]);

        $this->assertFalse(Schema::hasTable('ai_proposals'));
    }

    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function path(string $migration): string
    {
        return database_path('migrations/'.$migration);
    }

    /**
     * 表の列・制約(名前と定義)・索引(名前と定義)。
     *
     * @return array<string, array<mixed>>
     */
    private function definition(): array
    {
        $columns = 'select column_name, data_type, is_nullable, character_maximum_length, column_default '
            ."from information_schema.columns where table_name = 'ai_proposals' order by ordinal_position";
        $constraints = 'select conname, pg_get_constraintdef(oid) as definition from pg_constraint '
            ."where conrelid = 'ai_proposals'::regclass order by conname";
        $indexes = "select indexname, indexdef from pg_indexes where tablename = 'ai_proposals' order by indexname";

        return [
            'columns' => DB::select($columns),
            'constraints' => DB::select($constraints),
            'indexes' => DB::select($indexes),
        ];
    }
}
