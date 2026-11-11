<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\CreateRoutePrefixResourceGroup;
use App\Models\Authz\AuthzResourceGroup;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\DeletesResourceGroupTree;
use Tests\Support\TransactionalTestCase;

/**
 * ルートの名前の接頭辞のリソースグループを作る(docs の 設計/メニュー/認可との結び付け.md 2.3 の間の階層・2.4: 置き場(2 つのセットのどちらか)を受け、
 * ID = <セット>. + 接頭辞、親 = 1 つ短い接頭辞のリソースグループ(最も短い接頭辞はセット)。ほかの値の置き場・形の違う接頭辞・アプリのルートの名前・
 * 親の無い接頭辞を拒む)。
 */
final class CreateRoutePrefixResourceGroupTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;
    use DeletesResourceGroupTree;

    /**
     * @return array<string, array{string}>
     */
    public static function sets(): array
    {
        return ['http-services' => ['http-services'], '設定不可のセット' => ['http-services-unmanaged']];
    }

    #[DataProvider('sets')]
    public function test_creates_the_module_prefix_under_the_set_and_a_longer_prefix_under_it(string $set): void
    {
        $setGroup = AuthzResourceGroup::query()->where('code', $set)->sole();
        $next = (int) AuthzResourceGroup::query()->where('parent_id', $setGroup->id)->max('sort_key') + 1;

        $module = $this->create('example', $set, ['ja' => ['display_name' => '例']]);
        $admin = $this->create('example.admin', $set);

        $this->assertSame(
            ["{$set}.example", $setGroup->id, $next],
            [$module->code, $module->parent_id, $module->sort_key],
            '並び順は兄弟の最大 + 1',
        );
        $this->assertSame(['例'], $module->authzResourceGroupTranslations()->pluck('display_name')->all());
        $this->assertSame(["{$set}.example.admin", $module->id], [$admin->code, $admin->parent_id]);
        $this->assertNull($module->authzResource()->first());
    }

    public function test_creates_the_same_prefix_in_both_sets(): void
    {
        $managed = $this->create('example', 'http-services');
        $unmanaged = $this->create('example', 'http-services-unmanaged');

        $this->assertSame(
            ['http-services.example', 'http-services-unmanaged.example'],
            [$managed->code, $unmanaged->code],
        );
    }

    /**
     * 置き場は、ルートのリソースを置く 2 つのセットだけ(ほかのセット・セットの中のグループ・空は拒む)。
     */
    public function test_rejects_a_set_other_than_the_two(): void
    {
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejected('set', fn () => $this->create('example', 'im-menu-group'));
        $this->assertRejected('set', fn () => $this->create('example', 'http-services.menu'));
        $this->assertRejected('set', fn () => $this->create('example', ''));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    #[DataProvider('sets')]
    public function test_rejects_a_prefix_without_its_parent(string $set): void
    {
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejected('prefix', fn () => $this->create('example.admin', $set));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    /**
     * 親はその置き場の側の接頭辞だけ(もう一方のセットに同じ接頭辞があっても親にしない)。
     */
    public function test_rejects_a_prefix_whose_parent_is_only_in_the_other_set(): void
    {
        $this->create('example', 'http-services');
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejected('prefix', fn () => $this->create('example.admin', 'http-services-unmanaged'));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    public function test_rejects_a_prefix_without_the_set(): void
    {
        $this->deleteResourceGroupTree('http-services');
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejected('prefix', fn () => $this->create('example', 'http-services'));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    #[DataProvider('sets')]
    public function test_rejects_the_name_of_an_app_route_as_a_prefix(string $set): void
    {
        $this->create('test', $set);
        Route::get('/test/items', fn () => 'items')->name('test.items');
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejected('prefix', fn () => $this->create('test.items', $set));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    public function test_rejects_a_prefix_out_of_form(): void
    {
        $groups = AuthzResourceGroup::query()->count();

        $this->assertRejected('prefix', fn () => $this->create('Example', 'http-services'));
        $this->assertRejected('prefix', fn () => $this->create('example.', 'http-services-unmanaged'));
        $this->assertRejected('prefix', fn () => $this->create('', 'http-services'));
        $this->assertSame($groups, AuthzResourceGroup::query()->count());
    }

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations
     */
    private function create(string $prefix, string $set, array $translations = []): AuthzResourceGroup
    {
        return app(CreateRoutePrefixResourceGroup::class)->execute($prefix, $set, $translations);
    }
}
