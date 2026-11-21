<?php

namespace Tests\Unit\Actions\Ai\Core\Contract;

use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\LoopEndingKind;
use App\Actions\Ai\Core\ParallelTools;
use App\Actions\Ai\Core\StopKind;
use Laravel\Ai\Responses\Data\FinishReason;
use Tests\Unit\Actions\Ai\Core\Support\FakeStepGateway;
use Tests\Unit\Actions\Ai\Core\Support\NamedTool;

/**
 * 並べて動かす組の約束(docs の 設計/AI/会話の中核の口と受け手.md 13.3、設計/AI/エージェントの実行/道具の包みと止める.md 9.4): 並べてよい道具の
 * 続く呼び出しを 1 つの組にし、並べない道具・渡していない道具・引数の形が通らない呼び出しで組が切れる(呼び出しが 1 つの組は並べない)。組の前の確かめは 1 回で、当たれば組の
 * どの呼び出しも動かさない。
 */
abstract class ToolGroupsContractTestCase extends CoreContractTestCase
{
    private const MODULE = ['module' => 'm'];

    /**
     * read・search = 並べてよい、write = 並べない、missing = 渡していない。r4 は引数の形が通らない。
     *
     * @return list<mixed>
     */
    private static function scripts(): array
    {
        return [
            FakeStepGateway::response('', FinishReason::ToolCalls, [
                self::call('r1', 'read', self::MODULE),
                self::call('r2', 'search', self::MODULE),
                self::call('w3', 'write', self::MODULE),
                self::call('r4', 'read', ['module' => 1]),
                self::call('r5', 'read', self::MODULE),
                self::call('m6', 'missing', self::MODULE),
                self::call('r7', 'search', self::MODULE),
            ]),
            FakeStepGateway::response('答え'),
        ];
    }

    private static function parallel(): ParallelTools
    {
        return new ParallelTools(['read' => true, 'search' => true, 'write' => false, 'missing' => true], 2);
    }

    public function test_consecutive_parallel_calls_run_as_one_group_and_others_cut_it(): void
    {
        $tools = [new NamedTool('read'), new NamedTool('search'), new NamedTool('write')];

        $outcome = $this->loop(self::scripts(), tools: $tools, parallel: self::parallel());

        $this->assertSame(LoopEndingKind::Succeeded, $outcome->ending->kind);
        $this->assertSame([['r1', 'r2']], $this->tools->groups);
        $this->assertSame(['r1', 'r2', 'w3', 'r4', 'r5', 'm6', 'r7'], $this->tools->ran);
        $beforeTools = array_filter(
            $this->receivers->checks,
            static fn (CheckRequest $request): bool => $request->point === CheckPoint::BeforeTool,
        );
        $this->assertCount(6, $beforeTools);
    }

    public function test_a_stop_before_a_group_runs_none_of_it(): void
    {
        $tools = [new NamedTool('read'), new NamedTool('search'), new NamedTool('write')];
        $answer = static fn (CheckRequest $request): ?CheckReply => $request->point === CheckPoint::BeforeTool
            ? CheckReply::stop(StopKind::User)
            : null;

        $outcome = $this->loop(self::scripts(), answer: $answer, tools: $tools, parallel: self::parallel());

        $this->assertSame(LoopEndingKind::Stopped, $outcome->ending->kind);
        $this->assertSame([], $this->tools->ran);
        $this->assertSame([], $this->tools->groups);
    }
}
