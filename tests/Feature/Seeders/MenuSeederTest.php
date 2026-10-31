<?php

namespace Tests\Feature\Seeders;

use App\Enums\Menu\MenuType;
use App\Models\Authz\AuthzResource;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuGroup;
use App\Models\Menu\MenuGroupCategory;
use App\Models\Menu\MenuGroupCategoryInclusion;
use App\Models\Menu\MenuGroupCategoryTranslation;
use App\Models\Menu\MenuTranslation;
use App\Queries\Authz\RouteResources;
use Tests\Support\TransactionalTestCase;

/**
 * メニューの初期データ(docs の 設計/メニュー/初期データ.md 4 節): カテゴリ・アプリのグループ・アプリの木と、アイテムの指す先と数。
 * ポリシーの件数と付け忘れの検査は {@see AuthorizationSeedersTest}。
 * アイテム = ワークフロー 5 × 2(ホーム・申請する・申請一覧・申請書 2 = 設計/ワークフロー/認可と一覧.md の 6 節)、管理 6 × 2(認可設定・組織図・
 * 業務モジュール・モジュール分類・申請操作・代理の管理 = 設計/ワークフロー/案件の運用/認可と一覧.md の 5 節)、業務 3 × 2(業務モジュールを作る
 * 業務処理が足すプロジェクト・課題・会議。申請書の業務モジュール 2 つのアイテムはコンテンツを作る業務処理が消す = ADR 0031)、主な操作 3。
 */
final class MenuSeederTest extends TransactionalTestCase
{
    public function test_every_menu_item_points_to_an_external_site_or_an_app_route_with_its_resource(): void
    {
        $routeResources = app(RouteResources::class);

        $items = Menu::query()->where('type', MenuType::Item)->get();
        $invalid = $items->reject(function (Menu $item) use ($routeResources) {
            if (str_starts_with((string) $item->url, 'http://') || str_starts_with((string) $item->url, 'https://')) {
                return true;
            }

            $name = $routeResources->match($item->method->value, (string) $item->url)?->getName();

            return $name !== null && AuthzResource::query()->where('uri', $routeResources->uriOf($name))->exists();
        });

        $this->assertSame([], $invalid->pluck('code')->all());
        $this->assertSame(5 * 2 + 6 * 2 + 3 * 2 + 3, Menu::query()->where('type', MenuType::Item)->count());
    }

    public function test_seeds_the_display_names_and_the_selectability_of_the_categories_of_the_design(): void
    {
        $categories = MenuGroupCategory::query()->with('menuGroupCategoryTranslations.locale')->orderBy('code')->get()
            ->map(fn (MenuGroupCategory $category) => [
                $category->code,
                $this->localeNames($category->menuGroupCategoryTranslations),
                $category->is_selectable,
            ])
            ->all();

        $this->assertSame([
            ['admin_menu_pc', ['en Admin menu (PC)', 'ja 管理メニュー（PC用）'], true],
            ['home_quick_actions_pc', ['en Home quick actions (PC)', 'ja ホームの主な操作（PC用）'], true],
            ['im_global_nav_pc', ['en Global navigation (PC)', 'ja グローバルナビ（PC用）'], true],
            ['im_global_nav_sp', ['en Global navigation (smartphone)', 'ja グローバルナビ（スマートフォン用）'], true],
            ['im_personal_settings_pc', ['en Personal settings (PC)', 'ja 個人設定（PC用）'], true],
            ['im_site_help_pc', ['en Help (PC)', 'ja ヘルプ（PC用）'], true],
            ['im_sitemap_pc', ['en Sitemap (PC)', 'ja サイトマップ（PC用）'], true],
            ['im_sitemap_sp', ['en Sitemap (smartphone)', 'ja サイトマップ（スマートフォン用）'], true],
        ], $categories);
    }

    public function test_seeds_the_menu_groups_of_the_design(): void
    {
        $groups = MenuGroup::query()
            ->with(['menu.menuTranslations.locale', 'menuGroupCategoryInclusions.menuGroupCategory'])
            ->get()
            ->map(fn (MenuGroup $group) => [
                $group->menu?->code,
                $group->menuGroupCategoryInclusions
                    ->map(fn (MenuGroupCategoryInclusion $inclusion) => $inclusion->menuGroupCategory?->code)->all(),
                $group->menu?->sort_number,
                $group->menu?->icon_32,
                $group->menu?->notes,
                $this->localeNames($group->menu->menuTranslations ?? []),
            ])
            ->sortBy(fn (array $group) => $group[0])
            ->values()
            ->all();

        $this->assertSame([
            ['global-nav-admin', ['admin_menu_pc'], 1000, 'wrench', 'システムの設定', ['en Administration', 'ja 管理']],
            ['global-nav-main', ['im_global_nav_pc'], 900, 'briefcase', '業務のテーブル', ['en Work', 'ja 業務']],
            ['global-nav-workflow', ['im_global_nav_pc'], 50, 'send', '申請と承認', ['en Workflow', 'ja ワークフロー']],
            ['home-quick-actions', ['home_quick_actions_pc'], 0, null, null, ['en Quick actions', 'ja 主な操作']],
            ['sitemap-admin', ['im_sitemap_pc'], 1000, 'wrench', 'システムの設定', ['en Administration', 'ja 管理']],
            ['sitemap-main', ['im_sitemap_pc'], 900, 'briefcase', '業務のテーブル', ['en Work', 'ja 業務']],
            ['sitemap-workflow', ['im_sitemap_pc'], 50, 'send', '申請と承認', ['en Workflow', 'ja ワークフロー']],
        ], $groups);
    }

    /**
     * アプリの木(設計/メニュー/初期データ.md 4 節の表): ワークフローは見出しなしのホームの後に見出しのフォルダ「申請と承認」「申請書」
     * (メニュー ID = <グループ ID>-folder-<語>、アイコン folder)、管理は組織図を認可設定の後に置き、業務にはこの初期データのアイテムが無い
     * (全体のホームとサイトマップはどのアプリにも置かない)。経路 = メニュー ID を / でつないだもの(並びの順)。
     */
    public function test_seeds_the_trees_of_the_apps_of_the_design(): void
    {
        foreach (['global-nav', 'sitemap'] as $prefix) {
            $workflow = "{$prefix}-workflow";
            $applications = "{$workflow}-folder-applications";
            $forms = "{$workflow}-folder-forms";

            $this->assertSame([
                "{$workflow}-workflow-home",
                $applications, "{$applications}/{$workflow}-workflow-flows-index",
                "{$applications}/{$workflow}-workflow-cases-index",
                $forms, "{$forms}/{$workflow}-records-data-index-project-plan-application",
                "{$forms}/{$workflow}-records-data-index-issue-resolution-application",
            ], $this->treeOf($workflow));
            $this->assertSame(array_map(fn (string $suffix) => "{$prefix}-admin-{$suffix}", [
                'authz-overview', 'master-organization-chart',
                'records-admin-modules-index', 'records-admin-categories-index',
                'workflow-admin-cases-index', 'workflow-admin-proxies-index',
            ]), $this->treeOf("{$prefix}-admin"));
            $this->assertSame(array_map(fn (string $suffix) => "{$prefix}-main-records-{$suffix}", [
                'project', 'project_issue', 'meeting',
            ]), $this->treeOf("{$prefix}-main"));
            $this->assertSame(
                [['folder', 'ja 申請と承認'], ['folder', 'ja 申請書']],
                [$this->iconAndJaName($applications), $this->iconAndJaName($forms)],
            );
        }
    }

    /**
     * メニューの子孫の経路(メニュー自身を除き、子孫のメニュー ID を / でつなぐ。並び = sort_number・code の前順)。
     *
     * @return list<string>
     */
    private function treeOf(string $code, string $prefix = ''): array
    {
        $menu = Menu::query()->where('code', $code)->sole();
        $children = Menu::query()->where('parent_id', $menu->id)->orderBy('sort_number')->orderBy('code')->get();
        $paths = [];

        foreach ($children as $child) {
            $path = $prefix.$child->code;
            $paths = [...$paths, $path, ...$this->treeOf($child->code, "{$path}/")];
        }

        return $paths;
    }

    /**
     * @return array{string|null, string}
     */
    private function iconAndJaName(string $code): array
    {
        $menu = Menu::query()->with('menuTranslations.locale')->where('code', $code)->sole();
        $ja = $menu->menuTranslations->first(fn (MenuTranslation $translation) => $translation->locale?->code === 'ja');

        return [$menu->icon_32, 'ja '.$ja?->display_name];
    }

    /**
     * 表示名の行(「ロケールのコード 表示名」。ロケールのコードの順)。
     *
     * @param  iterable<MenuTranslation|MenuGroupCategoryTranslation>  $translations
     * @return list<string>
     */
    private function localeNames(iterable $translations): array
    {
        $names = [];

        foreach ($translations as $translation) {
            $names[] = "{$translation->locale?->code} {$translation->display_name}";
        }

        sort($names, SORT_STRING);

        return $names;
    }
}
