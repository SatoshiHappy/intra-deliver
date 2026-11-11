<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\CreateResource;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソースを、対になるリソースグループと一緒に作る(docs の 設計/メニュー/認可との結び付け.md 2.4、
 * 設計/共通マスタ/ロールと認可/リソース.md の authz_resources: リソースグループの規則に加えて、
 * リソースタイプが列挙にある、URI は UTF-8 で 2,000 バイト以下。並び順はリソースグループの「並び順の入れ方」を通る)。
 */
final class CreateResourceTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;
    use AssertsRejectedByRule;

    /** 並び順の検査制約の上限(docs の 設計/共通マスタ/共通の規則.md 4 節) */
    private const MAX_SORT_KEY = 999_999_999_999_999;

    public function test_creates_a_resource_with_its_resource_group(): void
    {
        $parent = AuthzResourceGroup::factory()->create();

        $resource = $this->create('example.menu.home', $parent->id, 'service', 'service://example/home', ['ja' => ['display_name' => 'ホーム']]);

        $group = $resource->authzResourceGroup()->firstOrFail();
        $this->assertSame(['service', 'service://example/home'], [$resource->resource_type, $resource->uri]);
        $this->assertSame(['example.menu.home', $parent->id], [$group->code, $group->parent_id]);
        $this->assertSame(['ホーム'], $group->authzResourceGroupTranslations()->pluck('display_name')->all());
    }

    public function test_accepts_a_uri_of_2000_bytes_in_utf8(): void
    {
        $uri = 'service:'.str_repeat('あ', 664);

        $resource = $this->create('a', null, 'service', $uri);

        $this->assertSame(2000, strlen($resource->uri));
    }

    public function test_rejects_a_uri_longer_than_2000_bytes_in_utf8_without_writing(): void
    {
        $uri = 'service:'.str_repeat('あ', 664).'a';

        $counts = $this->counts();

        $this->assertRejected('uri', fn () => $this->create('a', null, 'service', $uri));
        $this->assertSame($counts, $this->counts());
    }

    public function test_accepts_an_ascii_uri_of_1000_characters(): void
    {
        $uri = 'service:'.str_repeat('a', 992);

        $resource = $this->create('a', null, 'service', $uri);

        $this->assertSame(1000, mb_strlen($resource->uri));
    }

    public function test_rejects_a_resource_type_missing_from_the_registered_types_without_writing(): void
    {
        $counts = $this->counts();

        $this->assertRejected('resource_type', fn () => $this->create('a', null, 'no-such-type', 'no-such-type://a'));
        $this->assertSame($counts, $this->counts());
    }

    public function test_puts_the_maximum_of_the_siblings_plus_one_to_the_group_without_a_sort_key(): void
    {
        $empty = AuthzResourceGroup::factory()->create();
        $zero = $this->parentWithChildren([0]);
        $gap = $this->parentWithChildren([0, 5]);
        $this->parentWithChildren([99]);

        $this->assertSame(0, $this->sortKeyOf($this->create('a', $empty->id, 'service', 'service://a')));
        $this->assertSame(1, $this->sortKeyOf($this->create('b', $zero->id, 'service', 'service://b')));
        $this->assertSame(6, $this->sortKeyOf($this->create('c', $gap->id, 'service', 'service://c')));
    }

    public function test_puts_the_maximum_of_the_groups_without_a_parent_plus_one_at_the_top(): void
    {
        $top = (int) AuthzResourceGroup::query()->whereNull('parent_id')->max('sort_key') + 10;
        AuthzResourceGroup::factory()->create(['sort_key' => $top]);
        $this->parentWithChildren([$top + 50]);

        $this->assertSame($top + 1, $this->sortKeyOf($this->create('a', null, 'service', 'service://a')));
    }

    public function test_puts_a_given_sort_key_as_it_is(): void
    {
        $parent = $this->parentWithChildren([5]);

        $this->assertSame(2, $this->sortKeyOf($this->create('a', $parent->id, 'service', 'service://a', [], 2)));
        $this->assertSame(5, $this->sortKeyOf($this->create('b', $parent->id, 'service', 'service://b', [], 5)));
    }

    public function test_the_check_constraint_stops_the_maximum_plus_one_over_the_range_without_writing(): void
    {
        $parent = $this->parentWithChildren([self::MAX_SORT_KEY]);
        $counts = $this->counts();

        $create = fn () => $this->create('a', $parent->id, 'service', 'service://a');

        $this->assertRejectedBy('authz_resource_groups_sort_key_check', $create);
        $this->assertSame($counts, $this->counts());
    }

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations
     */
    private function create(string $code, ?int $parentId, string $resourceType, string $uri, array $translations = [], ?int $sortKey = null): AuthzResource
    {
        return app(CreateResource::class)->execute($code, $parentId, $resourceType, $uri, $translations, $sortKey);
    }

    private function sortKeyOf(AuthzResource $resource): int
    {
        return $resource->authzResourceGroup()->firstOrFail()->sort_key;
    }

    /**
     * 親のリソースグループと、その下の並び順 $sortKeys の子。
     *
     * @param  list<int>  $sortKeys
     */
    private function parentWithChildren(array $sortKeys): AuthzResourceGroup
    {
        $parent = AuthzResourceGroup::factory()->create();

        foreach ($sortKeys as $sortKey) {
            AuthzResourceGroup::factory()->create(['parent_id' => $parent->id, 'sort_key' => $sortKey]);
        }

        return $parent;
    }

    /**
     * リソースグループとリソースの件数。
     *
     * @return list<int>
     */
    private function counts(): array
    {
        return [AuthzResourceGroup::query()->count(), AuthzResource::query()->count()];
    }
}
