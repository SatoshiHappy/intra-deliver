<?php

namespace Tests\Unit\Enums\Records;

use App\Enums\Records\RecordModuleAction;
use PHPUnit\Framework\TestCase;

/**
 * 業務モジュールの認可のリソースグループ ID と URI(docs の 設計/業務データの入れ物/認可とメニュー/README.md 1 節: record-module-<コード>、
 * record-module://module/<コード>。セットは record-modules)。
 */
final class RecordModuleActionTest extends TestCase
{
    public function test_builds_the_resource_group_code_and_the_uri_from_the_module_code(): void
    {
        $this->assertSame('record-module-projects', RecordModuleAction::resourceGroupCode('projects'));
        $this->assertSame('record-module://module/projects', RecordModuleAction::uri('projects'));
        $this->assertSame('record-modules', RecordModuleAction::SET_CODE);
    }
}
