<?php

namespace Tests\Feature\Queries\Menu;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Actions\Authz\CreateRouteResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Actions\Menu\AddMenu;
use App\Actions\Menu\CreateMenuGroup;
use App\Actions\Menu\CreateMenuGroupCategory;
use App\Actions\Menu\MenuFolderInput;
use App\Actions\Menu\MenuItemInput;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Menu\MenuMethod;
use App\Models\Authz\AuthzResource;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuGroupCategory;
use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\MenuDisplayQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

/**
 * メニューの表示判定のテストの準備(docs の 設計/メニュー/表示.md 3.1)。判定を求める者は段 1b のゲスト。
 * テストのルートはこのファイルのクロージャ(vendor/ の外 = アプリのルート)で足す: test.permitted(GET。ゲストに execute を許す)、
 * test.forbidden(GET。許可なし)、test.posted(POST。ゲストに execute を許す)。
 */
trait ArrangesMenuDisplay
{
    private const GLOBAL_NAVIGATION = 'example_nav_pc';

    private const SITEMAP = 'example_sitemap_pc';

    private function addTestRoutes(): void
    {
        Route::get('/test/permitted', fn () => 'ok')->name('test.permitted');
        Route::get('/test/forbidden', fn () => 'ok')->name('test.forbidden');
        Route::post('/test/posted', fn () => 'ok')->name('test.posted');
    }

    /**
     * 2 つのカテゴリ(初期データのカテゴリと別のコード。ロケール・セットは初期データのもの)、テストのルートのリソースと、ゲストへの execute の許可(test.permitted・test.posted)。
     */
    private function arrangeAuthorization(): void
    {
        app(CreateMenuGroupCategory::class)->execute(self::GLOBAL_NAVIGATION, []);
        app(CreateMenuGroupCategory::class)->execute(self::SITEMAP, []);
        app(CreateRoutePrefixResourceGroup::class)->execute('test', []);

        foreach (['test.permitted' => true, 'test.forbidden' => false, 'test.posted' => true] as $routeName => $isPermitted) {
            $resource = app(CreateRouteResource::class)->execute($routeName, []);

            if ($isPermitted) {
                $this->permitGuest($resource->authz_resource_group_id, 'service', 'execute');
            }
        }
    }

    /**
     * グループを作り、ルートのメニューを返す。許すときはゲストにグループ(保存されたグループ ID)の read を許す。
     *
     * @param  array<string, string>  $names
     */
    private function group(string $code, string $categoryCode = self::GLOBAL_NAVIGATION, bool $isReadPermitted = true, int $sortNumber = 0, array $names = []): Menu
    {
        $category = MenuGroupCategory::query()->where('code', $categoryCode)->sole();
        $root = app(CreateMenuGroup::class)->execute($category->id, new MenuFolderInput($code, $names, sortNumber: $sortNumber))->menu()->sole();

        if ($isReadPermitted) {
            $this->permitGuest(AuthzResource::query()->where('uri', "im-menu-group://group/{$root->code}")->sole()->authz_resource_group_id, 'im-menu-group', 'read');
        }

        return $root;
    }

    private function folder(Menu $parent, string $code, int $sortNumber = 0): Menu
    {
        return app(AddMenu::class)->execute($parent->id, new MenuFolderInput($code, [], sortNumber: $sortNumber));
    }

    /**
     * @param  array<string, string>  $names
     */
    private function item(Menu $parent, string $code, string $url, array $names = [], int $sortNumber = 0, MenuMethod $method = MenuMethod::Get): Menu
    {
        return app(AddMenu::class)->execute($parent->id, new MenuItemInput($code, $url, $names, sortNumber: $sortNumber, method: $method));
    }

    private function permitGuest(int $resourceGroupId, string $resourceType, string $action): void
    {
        $guest = app(FindOrCreateSingleConditionSubjectGroup::class)->execute(new SubjectConditionInput(metaSubject: MetaSubject::Anonymous), []);
        app(CreatePolicy::class)->execute($resourceGroupId, $guest->id, $resourceType, $action, PolicyEffect::Permit);
    }

    /**
     * @return list<array{code: string, name: string|null, children: list<array<string, mixed>>}>
     */
    private function visibleGroups(string $categoryCode = self::GLOBAL_NAVIGATION): array
    {
        return app(MenuDisplayQuery::class)->visibleGroups($categoryCode, new Requester(null, null), CarbonImmutable::parse('2026-10-04'), 'ja');
    }
}
