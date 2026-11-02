<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\CreateRouteResource;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Queries\Authz\RouteResources;
use Illuminate\Support\Facades\Route;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * ルートの画面・処理のリソースを作る(docs の 設計/メニュー/認可との結び付け.md 2.3・2.4: 置き場はルートの定義の宣言から決め(呼び手は渡さない)、
 * ID = <セット>. + 名前、親 = 末端を除いた接頭辞のリソースグループ、URI = service:// + 名前の . を / に換えたもの(置き場によらない)。
 * 名前がアプリのルートに無ければ・名前の規則に合わなければ・親が置き場の側に無ければ拒む)。
 */
final class CreateRouteResourceTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;

    public function test_creates_the_service_resource_of_a_route_under_its_prefix(): void
    {
        Route::get('/test/items', fn () => 'items')->name('test.items.index');
        $prefix = $this->prefixGroups('test', 'test.items');

        $resource = $this->create('test.items.index', ['ja' => ['display_name' => '一覧']]);

        $group = $resource->authzResourceGroup()->firstOrFail();
        $this->assertSame(['service', 'service://test/items/index'], [$resource->resource_type, $resource->uri]);
        $this->assertSame(['http-services.test.items.index', $prefix->id, 0], [$group->code, $group->parent_id, $group->sort_key]);
        $this->assertSame(['一覧'], $group->authzResourceGroupTranslations()->pluck('display_name')->all());
    }

    public function test_creates_the_resource_of_a_route_declared_unmanaged_in_the_unmanaged_set(): void
    {
        Route::metadata(RouteResources::UNMANAGED)->get('/test/items', fn () => 'items')->name('test.items.index');
        $prefix = $this->prefixGroups('test', 'test.items', 'http-services-unmanaged');

        $resource = $this->create('test.items.index');

        $group = $resource->authzResourceGroup()->firstOrFail();
        $this->assertSame(['service', 'service://test/items/index'], [$resource->resource_type, $resource->uri]);
        $this->assertSame(['http-services-unmanaged.test.items.index', $prefix->id], [$group->code, $group->parent_id]);
    }

    /**
     * 親は宣言の置き場の側の接頭辞だけ(もう一方のセットに同じ接頭辞があっても親にしない)。
     */
    public function test_rejects_a_route_whose_prefix_is_only_in_the_other_set(): void
    {
        Route::metadata(RouteResources::UNMANAGED)->get('/test/unmanaged', fn () => 'ok')->name('test.unmanaged');
        Route::get('/test/managed', fn () => 'ok')->name('test.managed');
        $this->prefixGroups('test');
        $resources = AuthzResource::query()->count();

        $this->assertRejected('route_name', fn () => $this->create('test.unmanaged'));
        $this->assertSame($resources, AuthzResource::query()->count());
        $managed = $this->create('test.managed')->authzResourceGroup()->firstOrFail();
        $this->assertSame('http-services.test.managed', $managed->code);
    }

    public function test_rejects_a_name_that_no_app_route_has(): void
    {
        $this->prefixGroups('test');
        $resources = AuthzResource::query()->count();

        $this->assertRejected('route_name', fn () => $this->create('test.missing'));
        $this->assertRejected('route_name', fn () => $this->create('storage.local'));
        $this->assertSame($resources, AuthzResource::query()->count());
    }

    public function test_rejects_an_app_route_name_against_the_rules(): void
    {
        Route::get('/single', fn () => 'single')->name('single');
        Route::get('/test/admin', fn () => 'admin')->name('test.admin');
        Route::get('/test/admin/users', fn () => 'users')->name('test.admin.users');
        $this->prefixGroups('test');
        $resources = AuthzResource::query()->count();

        $this->assertRejected('route_name', fn () => $this->create('single'));
        $this->assertRejected('route_name', fn () => $this->create('test.admin'));
        $this->assertSame($resources, AuthzResource::query()->count());
    }

    public function test_rejects_a_route_without_its_prefix_resource_group(): void
    {
        Route::get('/test/items', fn () => 'items')->name('test.items.index');
        $this->prefixGroups('test');
        $resources = AuthzResource::query()->count();

        $this->assertRejected('route_name', fn () => $this->create('test.items.index'));
        $this->assertSame($resources, AuthzResource::query()->count());
    }

    /**
     * 初期データのセット $set の下に、渡した接頭辞のリソースグループを順に作る(最後のものを返す)。
     */
    private function prefixGroups(
        string $first,
        ?string $second = null,
        string $set = 'http-services',
    ): AuthzResourceGroup {
        $group = AuthzResourceGroup::query()->where('code', $set)->sole();

        foreach (array_filter([$first, $second]) as $prefix) {
            $group = AuthzResourceGroup::factory()->create(['code' => "{$set}.{$prefix}", 'parent_id' => $group->id]);
        }

        return $group;
    }

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations
     */
    private function create(string $routeName, array $translations = []): AuthzResource
    {
        return app(CreateRouteResource::class)->execute($routeName, $translations);
    }
}
