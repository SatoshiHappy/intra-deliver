<?php

namespace Tests\Unit\Actions\Ai\ToolCalls;

use App\Actions\Ai\ToolCalls\ToolScopes;
use LogicException;
use Tests\Feature\Ai\Tools\FakeTools;
use Tests\TestCase;

/**
 * 範囲の業務モジュールの名前と定義を道具に添える部品(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.3): 定義を読めた範囲の
 * 業務モジュールの参照制限の既定が無ければ作りの誤り(黙って制限なしにしない)。
 */
final class ToolScopesTest extends TestCase
{
    private const DEFINITION = [
        'code' => 'm_tasks',
        'name' => 'タスク',
        'description' => null,
        'fields' => [],
        'rules' => [],
    ];

    public function test_the_default_of_a_scoped_module_is_passed_through(): void
    {
        $granted = ToolScopes::of(
            [[FakeTools::create(), ['m_tasks']]],
            ['m_tasks' => 'タスク'],
            ['m_tasks' => self::DEFINITION],
            ['m_tasks' => true],
            [],
        );

        $this->assertTrue($granted[0]->scope->rowAccessDefault('m_tasks'));
    }

    public function test_a_scoped_module_without_its_default_is_a_construction_error(): void
    {
        $this->expectException(LogicException::class);

        ToolScopes::of(
            [[FakeTools::create(), ['m_tasks']]],
            ['m_tasks' => 'タスク'],
            ['m_tasks' => self::DEFINITION],
            [],
            [],
        );
    }
}
