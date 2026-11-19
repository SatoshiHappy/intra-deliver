<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\ToolCalls\ToolCallException;
use App\Actions\Ai\ToolCalls\ToolCallRows;
use App\Actions\Ai\ToolCalls\ToolStep;
use App\Actions\Ai\ToolCalls\ToolWriteJudge;
use App\Actions\Ai\Tools\RecordToolWrites;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiRunStopReason;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use App\Models\Records\Record;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 書く時の部品の止める印と例外と会話が無い(docs の 設計/AI/エージェントの実行/道具の包みと止める.md 9.4 の承認待ちの件と書く道具の
 * トランザクション・9.5、設計/AI/実行の記録.md 2.4 の T7): 道具の例外と包みの中の例外はその件の道具の誤りで実行は続き、行と会話の保存の
 * 書き込みの例外は受け手から投げる。会話が無くても書いた件は確定する。
 */
final class ToolCallWritesFailuresTest extends TransactionalTestCase
{
    use BuildsToolRecords;
    use RunsRecordTools;
    use RunsRecordWrites;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00', 'Asia/Tokyo'));
        $this->arrangeRequester();
        $this->arrangeTasks();
        $this->permit('m_tasks', [RecordModuleAction::Create, RecordModuleAction::Update]);
        $this->permit('m_refs', [RecordModuleAction::Read, RecordModuleAction::Create]);
        $this->startRecordToolRun(['m_tasks', 'm_refs'], ['ai_create_record', 'ai_update_record']);
    }

    /** 書く道具の中の例外は道具の誤り(理由なし)で、その件のトランザクションは戻って書かず、ほかの件は書く */
    public function test_an_exception_in_the_write_is_a_tool_error_and_rolls_back(): void
    {
        $rows = $this->ask([
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '落ちる']]],
            ['ai_create_record', ['module' => 'm_refs']],
        ]);
        $writes = Mockery::mock(RecordToolWrites::class, [app(CreateRecord::class), app(UpdateRecord::class)]);
        $writes->makePartial();
        $writes->shouldReceive('create')->once()->andThrow(new RuntimeException('壊れた'));
        $writes->shouldReceive('create')->passthru();
        $this->app->instance(RecordToolWrites::class, $writes);
        Exceptions::fake();

        $outcome = $this->answer($rows);

        $this->assertRow($rows[0], AiToolCallStatus::ToolError, null);
        $this->assertSame(AiInstructionTemplate::ToolError->value, $this->item($outcome, 'c1')->content['template']);
        $this->assertSame([], $rows[0]->refresh()->records);
        $this->assertRow($rows[1], AiToolCallStatus::Done, null);
        Exceptions::assertReported(ToolCallException::class);
    }

    /** 判定の関数の中の例外と、原文の期限で原文が消えた行は包みの中の例外(道具の誤り)で、実行は続いてほかの件を書く */
    public function test_an_exception_in_the_judge_and_a_row_without_written_arguments_are_tool_errors(): void
    {
        $rows = $this->ask([
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '判定']]],
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '引数']]],
            ['ai_create_record', ['module' => 'm_refs']],
        ]);
        $real = app(ToolWriteJudge::class);
        $calls = 0;
        $judge = Mockery::mock(ToolWriteJudge::class);
        $judge->shouldReceive('decide')->andReturnUsing(function (mixed ...$arguments) use ($real, &$calls): array {
            return $calls++ === 0 ? throw new RuntimeException('読めない') : $real->decide(...$arguments);
        });
        $this->app->instance(ToolWriteJudge::class, $judge);
        AiToolCallText::query()->whereKey($rows[1]->id)->delete();
        Exceptions::fake();

        $this->answer($rows);

        $this->assertRow($rows[0], AiToolCallStatus::ToolError, null);
        $this->assertRow($rows[1], AiToolCallStatus::ToolError, null);
        $this->assertRow($rows[2], AiToolCallStatus::Done, null);
        Exceptions::assertReported(ToolCallException::class);
    }

    /** 会話が無くても、書いた件の書き込みと行(済み)は確定し、ステップの結果は会話が無いで、残りの件は動かさない */
    public function test_a_missing_conversation_keeps_the_write_and_stops(): void
    {
        $rows = $this->ask([
            ['ai_create_record', ['module' => 'm_refs']],
            ['ai_create_record', ['module' => 'm_refs']],
        ]);
        $before = Record::query()->count();
        $conversationId = (int) DB::table('test_conversation_runs')
            ->where('ai_run_id', $this->run->id)
            ->value('conversation_id');
        $this->beforeAnswer = fn () => $this->store->deleteConversation($conversationId);

        $outcome = $this->answer($rows);

        $this->assertTrue($outcome->conversationMissing);
        $this->assertSame($before + 1, Record::query()->count());
        $this->assertSame(AiToolCallStatus::Done, $rows[0]->refresh()->status);
        $this->assertSame(AiToolCallStatus::AwaitingConfirmation, $rows[1]->refresh()->status);
    }

    /**
     * 会話の保存の足すの例外は受け手から投げ(道具の誤りにしない)、その件の書き込みは戻り、行は処理中のまま残る(後始末で結果不明 = 2.4 の
     * T7)
     */
    public function test_an_exception_while_appending_is_thrown_and_rolls_back_the_write(): void
    {
        $rows = $this->ask([['ai_create_record', ['module' => 'm_refs']]]);
        $before = Record::query()->count();
        $this->beforeAnswer = function (): void {
            $this->store->failAfterWrites = 0;
        };

        $this->assertThrows(fn () => $this->answer($rows), RuntimeException::class);

        $this->assertSame($before, Record::query()->count());
        $this->assertSame(AiToolCallStatus::Running, $rows[0]->refresh()->status);
    }

    /** 書く前の止める印で、まだ書いていない件は取り消しになり、中断の印が後ろに付く。登録に無い道具の件は止める印より先に無い道具 */
    public function test_a_stop_before_writing_cancels_the_rest_after_an_unknown_tool(): void
    {
        $rows = $this->ask([
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '1']]],
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '無い']]],
            ['ai_create_record', ['module' => 'm_tasks', 'values' => ['title' => '2']]],
        ]);
        AiToolCall::query()->whereKey($rows[1]->id)->update(['tool_name' => 'ai_gone']);
        $calls = 0;

        $outcome = $this->answer($rows, function () use (&$calls): ?AiRunStopReason {
            return ++$calls === 2 ? AiRunStopReason::User : null;
        });

        $this->assertRow($rows[0], AiToolCallStatus::Done, null);
        $this->assertRow($rows[1], AiToolCallStatus::ToolError, null);
        $this->assertRow($rows[2], AiToolCallStatus::Cancelled, null);
        $this->assertSame(AiRunStopReason::User, $outcome->stopReason);
        $this->assertNotNull($outcome->interruption);
    }

    /** 前の版の確認待ちの行(書く値の引数と直すの版が無い)は包みの中の例外(道具の誤り) */
    public function test_a_row_without_written_arguments_or_a_version_is_a_tool_error(): void
    {
        $id = $this->task(['title' => '旧']);
        $rows = $this->ask([
            ['ai_create_record', ['module' => 'm_refs']],
            ['ai_update_record', ['module' => 'm_tasks', 'id' => $id, 'values' => ['title' => '新']]],
        ]);
        AiToolCallText::query()->whereKey($rows[0]->id)->update(['written_arguments' => null]);
        AiToolCall::query()->whereKey($rows[1]->id)->update(['confirmation_version' => null]);
        Exceptions::fake();

        $this->answer($rows);

        $this->assertRow($rows[0], AiToolCallStatus::ToolError, null);
        $this->assertRow($rows[1], AiToolCallStatus::ToolError, null);
    }

    /** 答えの実行が終えられるのは、確認待ちの行か自分が処理中にした行だけ(ほかの実行が処理中にした行・終わった行は作りの誤り) */
    public function test_only_an_awaiting_row_or_a_row_of_this_run_is_finished(): void
    {
        [$row, $done] = $this->ask([
            ['ai_create_record', ['module' => 'm_refs']],
            ['ai_create_record', ['module' => 'm_refs']],
        ]);
        AiToolCall::query()->whereKey($row->id)->update([
            'answer' => 'approve',
            'answer_run_id' => AiRun::factory()->conversation()->create(['previous_run_id' => $this->run->id])->id,
            'status' => AiToolCallStatus::Running,
        ]);
        AiToolCall::query()->whereKey($done->id)->update(['decision' => 'deny', 'decision_reason' => 'rule',
            'status' => AiToolCallStatus::Rejected, 'finished_at' => now(), 'confirmation_version' => null]);
        $other = AiRun::factory()->conversation()->create();
        $otherStep = AiRunStep::factory()->create(['ai_run_id' => $other->id]);
        $step = new ToolStep($other, $otherStep, $this->granted, $this->store);
        $item = ConversationItem::toolResult('c1', AiInstructionTemplate::UserCancelled, [], null, true);
        $rows = app(ToolCallRows::class);

        $finish = fn () => $rows->answered($step, $row->refresh(), AiToolCallStatus::Done, null, null, [], $item);
        $this->assertThrows($finish, LogicException::class);
        $cancel = fn () => $rows->cancelledAnswers($step, [[$done->refresh(), $item]], []);
        $this->assertThrows($cancel, LogicException::class);
        $this->assertSame(AiToolCallStatus::Running, $row->refresh()->status);
    }

    /** 処理中にするのは確認待ちの行だけ(同じ件を 2 つの答えの実行が書かない) */
    public function test_only_an_awaiting_row_is_claimed(): void
    {
        [$row] = $this->ask([['ai_create_record', ['module' => 'm_refs']]]);
        $this->answer([$row]);

        $step = $this->answerStep;
        $this->assertNotNull($step);
        $this->assertThrows(fn () => app(ToolCallRows::class)->approved($step, $row->refresh()), LogicException::class);
    }
}
