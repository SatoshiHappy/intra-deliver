<?php

namespace Tests\Feature\Migrations;

use App\Models\Workflow\WorkflowContent;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * コンテンツに申請対象の 2 列と制約を足すマイグレーション(docs の 設計/ワークフロー/データモデル/表.md の 2.1 節、
 * 直列化と検査.md の 4 節): 初期データのコンテンツがある DB で down・up が通り、up の後は 2 列(NULL 可)と一意 1・外部キー 2・索引 2 があって
 * 今ある行の 2 列が NULL、down の後は 2 列と 5 つが無い。申請対象を持つ行があると down が何も変えずに止まる。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class WorkflowContentTargetMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_24_300001_add_target_record_field_to_workflow_contents_table.php';

    /** 足す列 */
    private const COLUMNS = ['target_record_field_id', 'target_record_module_id'];

    /** 足す制約と索引の名前(63 バイトに切り詰めた形 = 直列化と検査.md の 4 節) */
    private const NAMES = [
        'workflow_contents_record_module_id_target_record_field_id_forei',
        'workflow_contents_record_module_id_target_record_field_id_index',
        'workflow_contents_record_module_id_unique',
        'workflow_contents_target_record_field_id_target_record_module_i',
        'workflow_contents_target_record_module_id_target_record_field_i',
    ];

    public function test_up_on_a_database_with_contents_adds_the_columns_and_constraints_without_targets(): void
    {
        $contents = DB::table('workflow_contents')->count();
        $this->down();

        $this->up();

        $this->assertGreaterThan(0, $contents);
        $this->assertSame(['YES', 'YES'], $this->nullability());
        $this->assertSame(self::NAMES, $this->addedNames());
        $this->assertSame($contents, DB::table('workflow_contents')->count());
        $this->assertSame(0, DB::table('workflow_contents')->where(
            fn ($query) => $query->whereNotNull('target_record_field_id')->orWhereNotNull('target_record_module_id'),
        )->count());
    }

    public function test_down_removes_the_columns_and_constraints_and_up_runs_again(): void
    {
        $this->down();

        $this->assertSame([], $this->nullability());
        $this->assertSame([], $this->addedNames());

        $this->up();

        $this->assertSame(self::NAMES, $this->addedNames());
    }

    public function test_down_stops_without_changes_while_a_content_has_a_target(): void
    {
        WorkflowContent::factory()->withTarget()->createOne();
        $ran = DB::table('migrations')->count();

        $this->assertThrows(fn () => $this->down(), RuntimeException::class, '戻せない');

        $this->assertSame($ran, DB::table('migrations')->count());
        $this->assertSame(['YES', 'YES'], $this->nullability());
        $this->assertSame(self::NAMES, $this->addedNames());
    }

    /**
     * 足す列の NULL 可否(列の順。無い列は出ない)。
     *
     * @return list<string>
     */
    private function nullability(): array
    {
        return array_values(DB::table('information_schema.columns')->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', 'workflow_contents')->whereIn('column_name', self::COLUMNS)
            ->orderBy('ordinal_position')->pluck('is_nullable')
            ->map(fn (mixed $value): string => (string) $value)->all());
    }

    /**
     * 表にある、足す制約と索引の名前(名前の順)。
     *
     * @return list<string>
     */
    private function addedNames(): array
    {
        $constraints = DB::table('pg_constraint')->whereRaw("conrelid = 'workflow_contents'::regclass")
            ->pluck('conname')->all();
        $indexes = DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))
            ->where('tablename', 'workflow_contents')->pluck('indexname')->all();
        $names = array_values(array_intersect(array_unique([...$constraints, ...$indexes]), self::NAMES));
        sort($names);

        return $names;
    }

    private function down(): void
    {
        $this->migrator()->reset([$this->path()]);
    }

    private function up(): void
    {
        $this->migrator()->run([$this->path()]);
    }

    private function path(): string
    {
        return database_path('migrations/'.self::MIGRATION);
    }

    /**
     * Laravel のマイグレーターで、このマイグレーションだけを down・up する(パスに渡したファイルのマイグレーションだけが対象になる)。
     */
    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }
}
