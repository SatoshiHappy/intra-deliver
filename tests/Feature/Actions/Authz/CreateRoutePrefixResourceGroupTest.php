<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Models\Authz\AuthzResourceGroup;
use Illuminate\Support\Facades\Route;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\DeletesResourceGroupTree;
use Tests\Support\TransactionalTestCase;

/**
 * ルートの名前の接頭辞のリソースグループを作る(docs の 設計/メニュー/認可との結び付け.md 2.3 の間の階層・2.4: ID = http-services. + 接頭辞、
 * 親 = 1 つ短い接頭辞のリソースグループ(最も短い接頭辞はセット)。接頭辞がアプリのルートの名前なら・親が無ければ拒む)。
 */
final class CreateRoutePrefixResourceGroupTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;
    use DeletesResourceGroupTree;

    public function test_creates_the_module_prefix_under_the_set_and_a_longer_prefix_under_it(): void
    {
        $set = AuthzResourceGroup::query()->where('code', 'http-services')->sole();

        $module = $this->create('example', ['ja' => ['display_name' => '例']]);
        $admin = $this->create('example.admin');

        $this->assertSame(['http-services.example', $set->id, 0], [$module->code, $module->parent_id, $module->sort_key]);
        $this->assertSame(['例'], $module->authzResourceGroupTranslations()->pluck('display_name')->all());
        $this->assertSame(['http-services.example.admin', $module->id], [$admin->code, $admin->parent_id]);
        $this->assertNull($module->authzResource()->first());
    }

    public function test_rejects_a_prefix_without_its_parent(): void
    {
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejected('prefix', fn () => $this->create('example.admin'));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    public function test_rejects_a_prefix_without_the_set(): void
    {
        $this->deleteResourceGroupTree('http-services');
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejected('prefix', fn () => $this->create('example'));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    public function test_rejects_the_name_of_an_app_route_as_a_prefix(): void
    {
        $this->create('test');
        Route::get('/test/items', fn () => 'items')->name('test.items');
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejected('prefix', fn () => $this->create('test.items'));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    public function test_rejects_a_prefix_out_of_form(): void
    {
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejected('prefix', fn () => $this->create('Example'));
        $this->assertRejected('prefix', fn () => $this->create('example.'));
        $this->assertRejected('prefix', fn () => $this->create(''));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations
     */
    private function create(string $prefix, array $translations = []): AuthzResourceGroup
    {
        return app(CreateRoutePrefixResourceGroup::class)->execute($prefix, $translations);
    }
}
