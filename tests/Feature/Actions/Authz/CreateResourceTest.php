<?php

namespace Tests\Feature\Actions\Authz;

use App\Actions\Authz\CreateResource;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use Tests\Support\AssertsRejectedByRule;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソースを、対になるリソースグループと一緒に作る(docs の 設計/メニュー/認可との結び付け.md 2.4、
 * 設計/共通マスタ/ロールと認可/リソース.md の authz_resources: リソースグループの規則に加えて、
 * リソースタイプが列挙にある、URI は UTF-8 で 2,000 バイト以下)。
 */
final class CreateResourceTest extends TransactionalTestCase
{
    use AssertsRejectedByRule;

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

    /**
     * @param  array<string, array{display_name: string, description?: string|null}>  $translations
     */
    private function create(string $code, ?int $parentId, string $resourceType, string $uri, array $translations = []): AuthzResource
    {
        return app(CreateResource::class)->execute($code, $parentId, $resourceType, $uri, $translations);
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
