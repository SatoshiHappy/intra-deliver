<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールに「添付ファイルを使用するか」を足すマイグレーション(docs の
 * 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.8 節): down は検査制約と列を消し、
 * その後の up は、明細のモジュールの行を false・親の無い業務モジュールを true にしてから検査制約を足す。
 * DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class RecordModuleAttachmentMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_19_400008_add_is_attachment_enabled_to_record_modules_table.php';

    private const CHECK = 'record_modules_parent_record_module_id_is_attachment_enabled_check';

    public function test_down_drops_the_check_and_the_column(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertFalse(Schema::hasColumn('record_modules', 'is_attachment_enabled'));
        $this->assertSame([], $this->checkDefinitions());
    }

    public function test_up_sets_the_details_modules_to_false_and_the_others_to_true_and_adds_the_check(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        $parentId = $this->insertModule('attachment_parent', null);
        $this->insertModule('attachment_child', $parentId);

        $this->migrator()->run([$this->migrationPath()]);

        $flags = DB::table('record_modules')->orderBy('code')->pluck('is_attachment_enabled', 'code')->all();
        $this->assertTrue($flags['attachment_parent']);
        $this->assertFalse($flags['attachment_child']);
        $this->assertSame(
            [true, true, false],
            [$flags['project'], $flags['project_issue'], $flags['project_milestone']],
        );
        $this->assertSame(
            ['CHECK (((parent_record_module_id IS NULL) OR (NOT is_attachment_enabled)))'],
            $this->checkDefinitions(),
        );
    }

    /**
     * 列の無い表に、業務モジュールの行を書く(親の無い行はアイコンを持つ)。
     */
    private function insertModule(string $code, ?int $parentId): int
    {
        return DB::table('record_modules')->insertGetId([
            'code' => $code,
            'name' => $code,
            'icon' => $parentId === null ? 'table' : null,
            'parent_record_module_id' => $parentId,
            'sort_key' => 0,
        ]);
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

    /**
     * 添付の使用の検査制約の定義。無ければ空。
     *
     * @return list<string>
     */
    private function checkDefinitions(): array
    {
        return array_column(DB::select(
            'select pg_get_constraintdef(oid) as definition from pg_constraint'
            ." where conrelid = 'record_modules'::regclass and conname = ?",
            [substr(self::CHECK, 0, 63)],
        ), 'definition');
    }
}
