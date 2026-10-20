<?php

namespace Tests\Feature\Migrations;

use App\Actions\Authz\CreateSubjectGroup;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Tests\Support\TransactionalTestCase;

/**
 * 既にある対象者のカテゴリの並び順を埋めるマイグレーション(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 5.2): 行のある表では、カテゴリを作る
 * 業務処理と同じ表の並び順になり、表に無いカテゴリはその後。行の無い表でも通る。down はすべての行を 0 に戻す。DDL・DML もテストの TX の中で行い、
 * 終わりに巻き戻る。
 */
final class AuthzSubjectCategorySortKeyMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_20_400001_fill_sort_key_in_authz_subject_categories_table.php';

    public function test_up_fills_the_sort_keys_of_the_existing_rows_with_the_table_of_the_action(): void
    {
        $this->migrator()->reset([$this->path()]);
        DB::table('authz_subject_categories')->insert([
            ['code' => 'im_authz_ipv4', 'sort_key' => 0],
            ['code' => 'b_m_role:imm_user', 'sort_key' => 0],
        ]);
        $this->assertEquals([0], DB::table('authz_subject_categories')->distinct()->pluck('sort_key')->all());

        $this->migrator()->run([$this->path()]);

        $expected = [...CreateSubjectGroup::CATEGORY_SORT_KEYS];
        $expected['im_authz_ipv4'] = CreateSubjectGroup::OTHER_CATEGORY_SORT_KEY;
        $expected['b_m_role:imm_user'] = CreateSubjectGroup::OTHER_CATEGORY_SORT_KEY;
        $this->assertEquals($expected, DB::table('authz_subject_categories')->pluck('sort_key', 'code')->all());
        $ordered = DB::table('authz_subject_categories')->orderBy('sort_key')->orderByRaw('code COLLATE "C"')
            ->pluck('code');
        $this->assertSame([
            'im_authz_meta_subject',
            'b_m_role',
            'imm_user',
            'imm_department',
            'imm_company_post',
            'b_m_role:imm_user',
            'im_authz_ipv4',
        ], $ordered->all());
    }

    public function test_up_passes_on_an_empty_table(): void
    {
        $this->migrator()->reset([$this->path()]);
        DB::table('authz_subject_categories')->delete();

        $this->migrator()->run([$this->path()]);

        $this->assertSame(0, DB::table('authz_subject_categories')->count());
    }

    public function test_down_sets_every_sort_key_back_to_zero(): void
    {
        $this->migrator()->reset([$this->path()]);

        $this->assertEquals([0], DB::table('authz_subject_categories')->distinct()->pluck('sort_key')->all());
    }

    /**
     * Laravel のマイグレーターで、このマイグレーションだけを down・up する(パスに渡したファイルのマイグレーションだけが対象になる)。
     */
    private function migrator(): Migrator
    {
        return $this->app->make(Migrator::class);
    }

    private function path(): string
    {
        return database_path('migrations/'.self::MIGRATION);
    }
}
