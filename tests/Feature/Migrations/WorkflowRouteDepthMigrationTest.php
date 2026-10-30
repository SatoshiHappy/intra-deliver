<?php

namespace Tests\Feature\Migrations;

use Closure;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DropsUserOrDepartmentFields;
use Tests\Support\TransactionalTestCase;

/**
 * 段 3a のワークフローのマイグレーション(docs の 設計/ワークフロー/ルートの深さ/データモデル/直列化と検査.md 5 節・7 節の「マイグレーションの検査」): 段 2 の定義と案件の行は
 * 移さない(ルート・フロー・案件の行がある DB で up が止まる)、空の DB で down が段 2 の形に戻り、その後の up で段 3a の形になる、フロー・案件の行がある DB で down が止まる。
 * 段 3a のマイグレーション = 名前が {@see self::FIRST} 以降のもの。DDL もテストの TX の中で行い、終わりに巻き戻る。初期データのワークフローの行は TX の中で消す。
 */
final class WorkflowRouteDepthMigrationTest extends TransactionalTestCase
{
    use DropsUserOrDepartmentFields;

    private const FIRST = '2026_10_16_100001_create_workflow_flow_versions_table';

    /** 段 3a で足す表 */
    private const ADDED_TABLES = ['workflow_flow_versions', 'workflow_route_edges', 'workflow_route_send_backs', 'workflow_case_edges', 'workflow_case_send_backs', 'workflow_case_assignments'];

    public function test_down_stops_while_flows_or_cases_exist(): void
    {
        // 申請の行があると、後の版の参照のマイグレーションの down が先に止まるので、案件を消してフローの行だけを残す
        DB::statement('TRUNCATE workflow_cases CASCADE');
        $this->assertGreaterThan(0, DB::table('workflow_flows')->count());

        $this->assertThrows(fn () => $this->rollBack(), RuntimeException::class, 'workflow_flows に行がある');
        $this->assertTrue(Schema::hasTable('workflow_case_assignments'));
    }

    public function test_down_on_an_empty_database_returns_to_the_stage_2_form_and_up_adds_the_stage_3a_form_again(): void
    {
        $this->emptyWorkflow();

        $this->rollBack();

        $this->assertSame([false, false, false, false, false, false], array_map(fn (string $table): bool => Schema::hasTable($table), self::ADDED_TABLES));
        $this->assertTrue(Schema::hasTable('workflow_routes'));
        $this->assertSame([true, true], [Schema::hasColumn('workflow_route_nodes', 'workflow_route_id'), Schema::hasColumn('workflow_flows', 'workflow_route_id')]);
        $this->assertSame([false, false, false], [Schema::hasColumn('workflow_cases', 'base_date'), Schema::hasColumn('workflow_case_nodes', 'sequence'), Schema::hasColumn('workflow_case_histories', 'node_changes')]);
        $this->assertSame(1, $this->indexCount('workflow_case_nodes_workflow_case_id_unique'));
        $this->assertSame("CHECK (((kind)::text = ANY ((ARRAY['apply'::character varying, 'approve'::character varying])::text[])))", $this->checkDefinition('workflow_case_nodes_kind_check'));

        $this->migrator()->run([database_path('migrations')]);

        $this->assertSame([true, true, true, true, true, true], array_map(fn (string $table): bool => Schema::hasTable($table), self::ADDED_TABLES));
        $this->assertFalse(Schema::hasTable('workflow_routes'));
        $this->assertSame(0, $this->indexCount('workflow_case_nodes_workflow_case_id_unique'));
    }

    /**
     * 段 2 の形の DB に入れる行: 名前 → 行を入れる処理(ルートの行・フローの行・案件の行のどれか 1 つでも止まる)。
     *
     * @return array<string, array{Closure(self): mixed, string}>
     */
    public static function stage2Rows(): array
    {
        return [
            'ルートの行' => [fn (self $test) => $test->stage2Route(), 'workflow_routes'],
            'フローの行' => [fn (self $test) => $test->stage2Flow(), 'workflow_routes'],
            '案件の行' => [fn (self $test) => $test->stage2Case(), 'workflow_routes'],
        ];
    }

    /**
     * @param  Closure(self): mixed  $insert
     */
    #[DataProvider('stage2Rows')]
    public function test_up_stops_while_stage_2_rows_exist(Closure $insert, string $table): void
    {
        $this->emptyWorkflow();
        $this->rollBack();
        $insert($this);

        $this->assertThrows(fn () => $this->migrator()->run([database_path('migrations')]), RuntimeException::class, "{$table} に行がある");
        $this->assertFalse(Schema::hasTable('workflow_flow_versions'));
    }

    /**
     * 段 2 の形のルートの行(ルートだけ)。
     */
    public function stage2Route(): int
    {
        return DB::table('workflow_routes')->insertGetId(['code' => 'stage2_route', 'name' => '段 2 のルート']);
    }

    /**
     * 段 2 の形のフローの行(フローの行があればルートの行もある)。
     */
    public function stage2Flow(): int
    {
        $contentId = DB::table('workflow_contents')->insertGetId(['code' => 'stage2_content', 'name' => '段 2 のコンテンツ', 'record_module_id' => DB::table('record_modules')->whereNull('parent_record_module_id')->value('id')]);

        return DB::table('workflow_flows')->insertGetId(['code' => 'stage2_flow', 'name' => '段 2 のフロー', 'workflow_content_id' => $contentId, 'workflow_route_id' => $this->stage2Route()]);
    }

    /**
     * 段 2 の形の案件の行(一時保存)。
     */
    public function stage2Case(): void
    {
        DB::table('workflow_cases')->insert([
            'workflow_flow_id' => $this->stage2Flow(), 'state' => 'draft', 'user_id' => DB::table('users')->value('id'), 'draft_input' => '{}', 'revision' => 0,
        ]);
    }

    /**
     * 段 3a のマイグレーションを、正しい順に down する(戻す数は migrations の表から数える)。
     */
    private function rollBack(): void
    {
        // 後のマイグレーションの項目の検査制約を狭める down は、相対の既定値か 4000 字を超える複数行の最大の文字数の
        // 項目があると止まる(初期データの組み込みの会議 = docs の 設計/業務ツール/会議/README.md の 1.2)。TX の中で外してから戻す。
        DB::table('record_fields')->whereRaw("jsonb_typeof(default_value) = 'object'")
            ->update(['default_value' => null]);
        DB::table('record_fields')->where('max_length', '>', 4000)->update(['max_length' => null]);
        // 後のマイグレーションの申請対象の列の down は、申請対象を持つコンテンツの行があると止まる(初期データのコンテンツ)。
        // TX の中で申請対象を外してから戻す(docs の 設計/ワークフロー/初期データ.md の 3 節)。
        DB::table('workflow_contents')->update(['target_record_field_id' => null, 'target_record_module_id' => null]);
        // 後のマイグレーションの型の一覧を狭める down は、型「ユーザーと組織」の項目があると止まる。TX の中で消してから戻す
        $this->dropUserOrDepartmentFields();
        // 組み込みの旗の down は、旗の付いた行があると止まる。TX の中で旗を外してから戻す
        foreach (['record_modules', 'record_fields', 'record_field_options'] as $table) {
            DB::table($table)->update(['is_builtin' => false]);
        }
        $steps = DB::table('migrations')->where('migration', '>=', self::FIRST)->count();

        $rolledBack = $this->migrator()->rollback([database_path('migrations')], ['step' => $steps]);

        $this->assertCount($steps, $rolledBack);
    }

    /**
     * 初期データのワークフローの定義と案件の行を消す(TX の中)。
     */
    private function emptyWorkflow(): void
    {
        DB::statement('TRUNCATE workflow_flows, workflow_cases CASCADE');
    }

    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function indexCount(string $name): int
    {
        return DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))->where('indexname', $name)->count();
    }

    private function checkDefinition(string $name): ?string
    {
        $definition = DB::scalar('select pg_get_constraintdef(oid) from pg_constraint where conname = ?', [$name]);

        return is_string($definition) ? $definition : null;
    }
}
