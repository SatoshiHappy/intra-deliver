<?php

namespace Tests\Feature\Migrations;

use App\Models\Menu\Menu;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールにアイコンを足すマイグレーション(docs の 設計/業務データの入れ物/データモデル/表.md 2.1、制約の分担と検査.md 5 節・7 節):
 * down は検査制約と列を消し、その後の up は、親の無い行に global-nav-main のアイテムの icon_32 を(許可表にあるときだけ。ほかは table)写し、
 * 明細のモジュールを NULL のままにして、検査制約を足す。DDL もテストの TX の中で行い、終わりに巻き戻る。
 */
final class RecordModuleIconMigrationTest extends TransactionalTestCase
{
    private const MIGRATION = '2026_10_13_000001_add_icon_to_record_modules_table.php';

    private const ICON_CHECK = 'record_modules_parent_record_module_id_icon_check';

    public function test_down_drops_the_check_and_the_column(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);

        $this->assertFalse(Schema::hasColumn('record_modules', 'icon'));
        $this->assertSame([], $this->iconCheckDefinitions());
    }

    public function test_up_copies_the_icon_of_the_global_navigation_item_and_adds_the_check(): void
    {
        $this->migrator()->reset([$this->migrationPath()]);
        $this->moduleWithItems('icon_outside', ['global-nav-main' => 'house']);
        $this->moduleWithItems('icon_null', ['global-nav-main' => null]);
        $this->moduleWithItems('icon_sitemap_only', ['sitemap-main' => 'coins']);
        $this->moduleWithItems('icon_chosen', ['global-nav-main' => 'truck', 'sitemap-main' => 'coins']);

        $this->migrator()->run([$this->migrationPath()]);

        $this->assertSame([
            'icon_chosen' => 'truck',
            'icon_null' => 'table',
            'icon_outside' => 'table',
            'icon_sitemap_only' => 'table',
            'project' => 'folder-kanban',
            'project_issue' => 'list-todo',
            'project_milestone' => null,
        ], DB::table('record_modules')->orderBy('code')->pluck('icon', 'code')->all());
        $this->assertSame(['CHECK (((parent_record_module_id IS NULL) = (icon IS NOT NULL)))'], $this->iconCheckDefinitions());
    }

    /**
     * 親の無い業務モジュールの行(icon の列の無い表に書く)と、グループ ID → icon_32 で渡したアイテム。
     *
     * @param  array<string, string|null>  $icons
     */
    private function moduleWithItems(string $code, array $icons): void
    {
        DB::table('record_modules')->insert(['code' => $code, 'name' => $code, 'sort_key' => 0]);

        foreach ($icons as $group => $icon) {
            Menu::factory()->item()->create(['code' => "{$group}-records-{$code}", 'icon_32' => $icon]);
        }
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
     * アイコンの検査制約の定義。無ければ空。
     *
     * @return list<string>
     */
    private function iconCheckDefinitions(): array
    {
        return array_column(DB::select(
            "select pg_get_constraintdef(oid) as definition from pg_constraint where conrelid = 'record_modules'::regclass and conname = ?",
            [self::ICON_CHECK],
        ), 'definition');
    }
}
