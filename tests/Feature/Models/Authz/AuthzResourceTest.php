<?php

namespace Tests\Feature\Models\Authz;

use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソース(docs の 設計/共通マスタ/ロールと認可/リソース.md: URI はシステムで一意、リソースグループと 1 対 1、URI はリソースタイプとコロンで始まる)。
 */
final class AuthzResourceTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * URI がリソースタイプとコロンで始まる組(本家の標準のリソースタイプと、拡張 PG のリソースタイプの例)。
     *
     * @return array<string, array{string, string}>
     */
    public static function urisStartingWithTheType(): array
    {
        return [
            'service' => ['service', 'service://authz/settings/basic'],
            'im-menu-group' => ['im-menu-group', 'im-menu-group:im_global_nav_pc/x'],
            'sample-data' => ['sample-data', 'sample-data://im-authz-ext-sample/data/1'],
        ];
    }

    /**
     * URI がリソースタイプとコロンで始まらない組(タイプの後がコロンでない・コロンが無い・大文字小文字が違う・先頭がタイプでない・別のタイプ)。
     *
     * @return array<string, array{string, string}>
     */
    public static function urisNotStartingWithTheType(): array
    {
        return [
            'another letter after the type' => ['service', 'servicex://a'],
            'no colon' => ['service', 'service'],
            'another case' => ['service', 'Service://a'],
            'a colon before the type' => ['service', ':service://a'],
            'another type' => ['im-menu-group', 'service://a'],
        ];
    }

    #[DataProvider('urisStartingWithTheType')]
    public function test_accepts_a_uri_that_starts_with_the_resource_type_and_a_colon(string $resourceType, string $uri): void
    {
        $resource = AuthzResource::factory()->create(['resource_type' => $resourceType, 'uri' => $uri]);

        $this->assertModelExists($resource);
    }

    #[DataProvider('urisNotStartingWithTheType')]
    public function test_rejects_a_uri_that_does_not_start_with_the_resource_type_and_a_colon(string $resourceType, string $uri): void
    {
        $this->assertRejectedBy(
            'authz_resources_uri_resource_type_check',
            fn () => AuthzResource::factory()->create(['resource_type' => $resourceType, 'uri' => $uri]),
        );
    }

    public function test_rejects_a_duplicate_uri(): void
    {
        $existing = AuthzResource::factory()->create();

        $this->assertRejectedBy('authz_resources_uri_unique', fn () => AuthzResource::factory()->create(['uri' => $existing->uri]));
    }

    public function test_rejects_a_second_resource_for_one_group(): void
    {
        $existing = AuthzResource::factory()->create();

        $this->assertRejectedBy(
            'authz_resources_authz_resource_group_id_unique',
            fn () => AuthzResource::factory()->create(['authz_resource_group_id' => $existing->authz_resource_group_id]),
        );
    }

    public function test_accepts_a_group_without_a_resource(): void
    {
        $group = AuthzResourceGroup::factory()->create();

        $this->assertNull($group->authzResource);
    }
}
