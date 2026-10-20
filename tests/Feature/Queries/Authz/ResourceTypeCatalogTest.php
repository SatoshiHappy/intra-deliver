<?php

namespace Tests\Feature\Queries\Authz;

use App\Queries\Authz\ResourceTypeCatalog;
use App\Queries\Authz\ResourceTypes;
use LogicException;
use Tests\Support\TransactionalTestCase;

/**
 * 認可のリソースタイプとアクションの一覧の和(docs の 設計/メニュー/認可との結び付け.md 2.1)。
 */
final class ResourceTypeCatalogTest extends TransactionalTestCase
{
    public function test_the_registered_lists_form_a_union_without_duplicate_resource_types_and_include_service_and_menu_groups(): void
    {
        $catalog = app(ResourceTypeCatalog::class);

        $this->assertSame(['execute'], $catalog->actionsByResourceType()['service']);
        $this->assertSame(['read', 'admin'], $catalog->actionsByResourceType()['im-menu-group']);
        $this->assertTrue($catalog->hasAction('service', 'execute'));
        $this->assertFalse($catalog->hasAction('service', 'read'));
        $this->assertFalse($catalog->hasResourceType('no-such-type'));
    }

    public function test_includes_the_record_module_type_registered_by_records(): void
    {
        $catalog = app(ResourceTypeCatalog::class);

        $this->assertSame(
            ['read', 'create', 'update', 'delete', 'read-all'],
            $catalog->actionsByResourceType()['record-module'],
        );
        $this->assertFalse($catalog->hasAction('record-module', 'admin'));
    }

    public function test_joins_the_lists_of_several_modules(): void
    {
        $catalog = new ResourceTypeCatalog([$this->listOf(['a' => ['read']]), $this->listOf(['b' => ['read', 'admin']])]);

        $this->assertSame(['a' => ['read'], 'b' => ['read', 'admin']], $catalog->actionsByResourceType());
        $this->assertTrue($catalog->hasAction('b', 'admin'));
    }

    public function test_throws_when_two_lists_define_the_same_resource_type(): void
    {
        $catalog = new ResourceTypeCatalog([$this->listOf(['a' => ['read']]), $this->listOf(['a' => ['admin']])]);

        $this->expectException(LogicException::class);

        $catalog->hasAction('a', 'admin');
    }

    /**
     * @param  array<string, list<string>>  $actionsByResourceType
     */
    private function listOf(array $actionsByResourceType): ResourceTypes
    {
        return new readonly class($actionsByResourceType) implements ResourceTypes
        {
            /**
             * @param  array<string, list<string>>  $actionsByResourceType
             */
            public function __construct(private array $actionsByResourceType) {}

            public function actionsByResourceType(): array
            {
                return $this->actionsByResourceType;
            }
        };
    }
}
