<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Conversation\RunToolChildren;
use App\Actions\Ai\Conversation\RunTools;
use App\Actions\Ai\Conversation\RunToolStep;
use App\Actions\Ai\Conversation\ToolCallChildInput;
use App\Actions\Ai\Conversation\ToolChildRunner;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\ToolRun;
use App\Actions\Ai\Core\ToolRunAnswer;
use App\Actions\Ai\ToolCalls\ModelToolCall;
use App\Actions\Ai\ToolCalls\ToolCallRows;
use App\Actions\Ai\ToolCalls\ToolCallStages;
use App\Actions\Ai\ToolCalls\ToolCallWrites;
use App\Actions\Ai\ToolCalls\ToolStep;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\AiToolDenialReason;
use App\Models\Records\Record;
use App\Queries\Records\Api\RecordViewAccess;
use Illuminate\Support\Facades\Exceptions;
use Tests\Feature\Ai\RunsToolCalls;
use Tests\Support\TransactionalTestCase;

/**
 * 並べて動かす組の繋ぎ(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4 のテスト): 結果は呼び出しの順に書かれ、1 つの子が落ちても
 * ほかの子の結果は書かれる。落ちた子の道具の行は、無ければ包みの中の例外の値で作り、処理中なら道具の例外の値で終える。子は依頼者の今の権限と
 * 実行の始めの渡せる範囲で判定する(1 つずつの組と同じ)。
 */
final class RunToolChildrenTest extends TransactionalTestCase
{
    use RunsToolCalls;

    private InProcessChildren $children;

    private RunToolStep $cursor;

    private RunTools $tools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeWrapper();
        $this->children = new InProcessChildren;
        $this->app->instance(ToolChildRunner::class, $this->children);
        $this->cursor = new RunToolStep($this->run, $this->granted, $this->store);
        $this->cursor->begin($this->step->id);
        $stages = app(ToolCallStages::class);
        $this->tools = new RunTools($this->cursor, $stages, app(ToolCallWrites::class), app(RunToolChildren::class));
    }

    /**
     * @param  non-empty-list<array{string, string}>  $calls  呼び出しの id と業務モジュール(どれも ai_read)
     * @return list<ToolRun>
     */
    private function ranParallel(array $calls): array
    {
        $core = array_map(
            static fn (array $call): CoreToolCall => new CoreToolCall($call[0], 'ai_read', ['module' => $call[1]]),
            $calls,
        );

        foreach ($core as $call) {
            $this->tools->find($call, false);
        }

        $runs = $this->tools->runParallel($core, 2);
        $this->assertFalse($runs->conversationGone);

        return $runs->runs;
    }

    public function test_results_are_written_in_call_order_and_a_failed_child_fails_alone(): void
    {
        Exceptions::fake();
        $this->children->failing['c2'] = static function (): void {};

        $runs = $this->ranParallel([['c1', 'm_view'], ['c2', 'm_view'], ['c3', 'm_view']]);

        $this->assertSame([ToolRunAnswer::Result, ToolRunAnswer::Result, ToolRunAnswer::Result], array_map(
            static fn (ToolRun $run): ToolRunAnswer => $run->answer,
            $runs,
        ));
        $stored = $this->storedContents();
        $this->assertSame(['c1', 'c2', 'c3'], array_column($stored, 'call_id'));
        $this->assertSame(AiInstructionTemplate::ToolError->value, $stored[1]['template']);
        $this->assertSame([1, AiToolCallStatus::Done], [$this->row('c1')->position, $this->row('c1')->status]);
        $failed = $this->row('c2');
        $this->assertSame([2, AiToolCallDecision::WrapperException, AiToolCallStatus::ToolError], [
            $failed->position,
            $failed->decision,
            $failed->status,
        ]);
        $this->assertSame([3, AiToolCallStatus::Done], [$this->row('c3')->position, $this->row('c3')->status]);
        $this->assertSame([2], $this->children->concurrencies);
        $prior = $this->cursor->prior();
        $this->assertSame(['c1', 'c2', 'c3'], array_map(static fn ($prior): string => $prior->callId, $prior));
        $this->assertSame([['module' => 'm_view'], null], [$prior[0]->arguments, $prior[1]->arguments]);
    }

    public function test_a_child_that_fails_after_marking_its_row_running_ends_the_row_as_a_tool_error(): void
    {
        $this->children->failing['c1'] = function (ToolCallChildInput $input): void {
            $step = new ToolStep($this->run, $this->step, $this->granted, $this->store);
            $call = new ModelToolCall($input->call->id, $input->call->name, $input->call->arguments);
            app(ToolCallRows::class)->running($step, $input->position, $call);
        };

        $this->ranParallel([['c1', 'm_view'], ['c2', 'm_view']]);

        $row = $this->row('c1');
        $this->assertSame([AiToolCallDecision::Allow, AiToolCallStatus::ToolError], [$row->decision, $row->status]);
        $stored = $this->storedContents();
        $this->assertSame(AiInstructionTemplate::ToolError->value, $stored[0]['template']);
        $this->assertSame([2, AiToolCallStatus::Done], [$this->row('c2')->position, $this->row('c2')->status]);
        $this->assertSame(['c1', 'c2'], array_column($stored, 'call_id'));
    }

    public function test_a_view_revoked_before_the_child_reads_is_denied(): void
    {
        $this->children->before = $this->revokeRole(...);

        $this->ranParallel([['c1', 'm_view']]);

        $row = $this->row('c1');
        $denied = [AiToolCallDecision::Deny, AiToolDenialReason::NoPermission->value];
        $this->assertSame($denied, [$row->decision, $row->decision_reason]);
    }

    public function test_a_module_outside_the_run_start_scope_is_denied_in_the_child(): void
    {
        $this->ranParallel([['c1', 'm_out'], ['c2', 'm_view']]);

        $this->assertSame([AiToolCallDecision::Deny, AiToolDenialReason::OutOfScope->value], [
            $this->row('c1')->decision,
            $this->row('c1')->decision_reason,
        ]);
        $this->assertSame(AiToolCallStatus::Done, $this->row('c2')->status);
    }

    public function test_the_child_does_not_return_a_record_the_requester_cannot_view(): void
    {
        $module = $this->modules['m_view']->id;
        $record = fn (bool $restricted): int => Record::factory()
            ->create(['record_module_id' => $module, 'row_access_restricted' => $restricted])->id;
        [$visible, $hidden] = [$record(false), $record(true)];
        $this->tool('ai_read')->executor = static function (
            array $arguments,
            AgentToolSubject $subject,
        ): AgentToolResult {
            $id = (int) $arguments['id'];
            $seen = app(RecordViewAccess::class)->canView($subject->requester(), 'm_view', $id, $subject->date());

            return $seen ? new AgentToolResult(['id' => $id]) : AgentToolResult::notFound();
        };
        $core = [
            new CoreToolCall('c1', 'ai_read', ['module' => 'm_view', 'id' => $hidden]),
            new CoreToolCall('c2', 'ai_read', ['module' => 'm_view', 'id' => $visible]),
        ];
        array_map(fn (CoreToolCall $call) => $this->tools->find($call, false), $core);

        $this->tools->runParallel($core, 2);

        $bodies = array_column($this->storedContents(), 'body');
        $this->assertSame(json_encode(['error' => AgentToolResult::NOT_FOUND], JSON_UNESCAPED_UNICODE), $bodies[0]);
        $this->assertSame('{"id":'.$visible.'}', $bodies[1]);
    }

    public function test_a_gone_conversation_is_reported(): void
    {
        $this->store->deleteConversation($this->conversationId);
        $call = new CoreToolCall('c1', 'ai_read', ['module' => 'm_view']);
        $this->tools->find($call, false);

        $this->assertTrue($this->tools->runParallel([$call], 2)->conversationGone);
    }
}
