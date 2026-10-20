<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\CreateRouteResource;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use Illuminate\Support\Facades\Route;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * ルートの画面・処理のリソースを作る(docs の 設計/メニュー/認可との結び付け.md 2.3・2.4: ID = http-services. + 名前、親 = 末端を除いた接頭辞の
 * リソースグループ、URI = service:// + 名前の . を / に換えたもの。名前がアプリのルートに無ければ・名前の規則に合わなければ・親が無ければ拒む)。
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
     * 初期データのセットの下に、渡した接頭辞のリソースグループを順に作る(最後のものを返す)。
     */
    private function prefixGroups(string ...$prefixes): AuthzResourceGroup
    {
        $group = AuthzResourceGroup::query()->where('code', 'http-services')->sole();

        foreach ($prefixes as $prefix) {
            $group = AuthzResourceGroup::factory()->create(['code' => "http-services.{$prefix}", 'parent_id' => $group->id]);
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
