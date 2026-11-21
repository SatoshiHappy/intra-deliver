<?php

namespace Tests\Unit\Actions\Ai\Core;

use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\ToolGroup;
use App\Actions\Ai\Core\ToolGroups;
use Tests\Unit\Actions\Ai\Core\Support\NamedTool;

/**
 * 呼び出しの組の分け方(docs の 設計/AI/会話の中核の口と受け手.md 13.3): 並べてよい道具の続く呼び出しを
 * 1 つの組にし、並べてよくない道具・渡していない道具・引数の形が通らない呼び出しは 1 つずつの組にして前後を切る。
 */
final class ToolGroupsTest extends EmptyContainerTestCase
{
    /**
     * @param  list<string>  $names
     * @param  array<int, array<string, mixed>>  $arguments  呼び出しの番号 → 引数(無い番号は module = m)
     * @return list<array{bool, list<string>}>
     */
    private static function grouped(array $names, array $arguments = []): array
    {
        $calls = array_map(
            static fn (string $name, int $index): CoreToolCall => new CoreToolCall(
                "c{$index}",
                $name,
                $arguments[$index] ?? ['module' => 'm'],
            ),
            $names,
            array_keys($names),
        );
        $toolNames = ['read', 'search', 'write'];
        $tools = array_combine($toolNames, array_map(
            static fn (string $name): NamedTool => new NamedTool($name),
            $toolNames,
        ));
        $parallel = ['read' => true, 'search' => true, 'write' => false];

        return array_map(
            static fn (ToolGroup $group): array => [$group->parallel, array_map(
                static fn (CoreToolCall $call): string => $call->callId,
                $group->calls,
            )],
            ToolGroups::of($calls, $tools, $parallel),
        );
    }

    public function test_consecutive_parallel_calls_share_a_group(): void
    {
        $this->assertSame([[true, ['c0', 'c1', 'c2']]], self::grouped(['read', 'search', 'read']));
        $this->assertSame([[false, ['c0']]], self::grouped(['read']));
    }

    public function test_a_serial_call_cuts_the_groups(): void
    {
        $this->assertSame(
            [[false, ['c0']], [false, ['c1']], [false, ['c2']], [true, ['c3', 'c4']]],
            self::grouped(['read', 'write', 'write', 'read', 'search']),
        );
    }

    public function test_unknown_tools_and_invalid_arguments_are_serial(): void
    {
        $this->assertSame(
            [[false, ['c0']], [false, ['c1']], [true, ['c2', 'c3']], [false, ['c4']], [false, ['c5']]],
            self::grouped(['read', 'missing', 'read', 'search', 'read', 'read'], [4 => ['module' => 1]]),
        );
        $this->assertSame([], self::grouped([]));
    }
}
