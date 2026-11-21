<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Conversation\AnswerCalls;
use App\Actions\Ai\Conversation\AnswerNotConfirmed;
use App\Actions\Ai\Conversation\AnswerRunStart;
use App\Actions\Ai\Conversation\EndAwaitingToolCalls;
use App\Actions\Ai\Conversation\RunClock;
use App\Actions\Ai\Conversation\RunNotifier;
use App\Actions\Ai\Conversation\RunProgress;
use App\Actions\Ai\Conversation\RunToolChildren;
use App\Actions\Ai\Conversation\RunTools;
use App\Actions\Ai\Conversation\RunToolStep;
use App\Actions\Ai\Conversation\StepRecorder;
use App\Actions\Ai\Core\AppendNotice;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\ToolLookupAnswer;
use App\Actions\Ai\Core\ToolRunAnswer;
use App\Actions\Ai\ToolCalls\ToolCallRows;
use App\Actions\Ai\ToolCalls\ToolCallStages;
use App\Actions\Ai\ToolCalls\ToolCallWrites;
use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\ConversationItemType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use App\Queries\Ai\RunChainCalls;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\BuildsToolRecords;
use Tests\Feature\Ai\RunsRecordTools;
use Tests\Feature\Ai\RunsRecordWrites;
use Tests\Support\TransactionalTestCase;

/**
 * 答えの実行(docs の 設計/AI/エージェントの実行/README.md 9.3 の H1・H2 と答えの実行の始めの確かめ、止めたで終える時の取り消し =
 * 設計/AI/エージェントの実行/道具の包みと止める.md 9.5、行の状態 = 設計/AI/実行の記録.md 2.4 の T5〜T8)。
 */
final class AnswerRunsTest extends TransactionalTestCase
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
        $this->startRecordToolRun(['m_tasks'], ['ai_create_record']);
    }

    /** H1: 承認した件は登録から引いて書く時の部品で書く。登録に無い道具は探すの時に無い道具の結果になる */
    public function test_approved_calls_are_written_and_an_unknown_tool_is_missing(): void
    {
        $rows = $this->asked(['1', '2']);
        AiToolCall::query()->whereKey($rows[1]->id)->update(['tool_name' => 'ai_gone']);
        [$tools] = $this->approvedTools([$rows[0], $rows[1]->refresh()]);
        $first = new CoreToolCall('c1', 'ai_create_record', []);

        $this->assertSame(ToolLookupAnswer::Found, $tools->find($first, true)->answer);
        $this->assertSame(ToolRunAnswer::Result, $tools->run($first, true)->answer);
        $missing = $tools->find(new CoreToolCall('c2', 'ai_gone', []), true);

        $this->assertSame(ToolLookupAnswer::Missing, $missing->answer);
        $this->assertSame(AiInstructionTemplate::UnknownTool->value, $missing->missingResult?->content['template']);
        $this->assertRow($rows[0], AiToolCallStatus::Done, null);
        $this->assertRow($rows[1], AiToolCallStatus::ToolError, null);
    }

    /** @return array<string, array{AiInstructionTemplate}> */
    public static function stopTemplates(): array
    {
        return [
            '利用者' => [AiInstructionTemplate::UserCancelled],
            '時間の上限' => [AiInstructionTemplate::StoppedByTimeLimit],
        ];
    }

    /** 書く前に止めた件: 中核が付けた結果は前の実行の行を使い(新しい行を作らない)、結果と同じトランザクションで実行の答えのまま取り消す(T7) */
    #[DataProvider('stopTemplates')]
    public function test_a_stop_before_an_approved_call_cancels_its_row_with_the_result(
        AiInstructionTemplate $template,
    ): void {
        $rows = $this->asked(['1']);
        [, $cursor] = $this->approvedTools($rows);
        $before = AiToolCall::query()->count();

        $this->notifier($cursor)->notify(new AppendNotice([
            ConversationItem::toolResult('c1', $template, [], null, true),
            ConversationItem::interruption(true),
        ]));
        $answerRun = $this->answerRun ?? $this->fail('答えの実行が無い');

        $this->assertSame($before, AiToolCall::query()->count());
        $this->assertRow($rows[0], AiToolCallStatus::Cancelled, null);
        $items = $this->store->read($answerRun->id) ?? [];
        $types = array_map(fn (ConversationItem $item) => $item->type, $items);
        $expected = [ConversationItemType::ToolResult, ConversationItemType::Interruption];
        $this->assertSame($expected, array_slice($types, -2));
    }

    /**
     * 答えの実行の始めの確かめが合わないときは、どの件も書かずに失敗(確認)にする
     *
     * @return array<string, array{Closure(self, list<AiToolCall>): array<int, AiToolCall>}>
     */
    public static function mismatches(): array
    {
        return [
            '確認待ちの呼び出しが無い' => [fn (self $test, array $rows): array => []],
            '会話の保存に無い' => [function (self $test, array $rows): array {
                AiToolCall::query()->whereKey($rows[0]->id)->update(['call_id' => 'other']);

                return [$rows[0]->refresh()];
            }],
            '引数が違う' => [function (self $test, array $rows): array {
                AiToolCallText::query()->whereKey($rows[0]->id)->update(['arguments' => ['module' => 'm_refs']]);

                return $rows;
            }],
            '原文が期限で消えた' => [function (self $test, array $rows): array {
                AiToolCallText::query()->whereKey($rows[0]->id)->delete();

                return $rows;
            }],
        ];
    }

    /** @param  Closure(self, list<AiToolCall>): array<int, AiToolCall>  $change */
    #[DataProvider('mismatches')]
    public function test_a_mismatch_at_the_answer_run_start_writes_nothing(Closure $change): void
    {
        $rows = array_values($change($this, $this->asked(['1'])));
        $this->answerRun();
        $answerRun = $this->answerRun ?? $this->fail('答えの実行が無い');
        $items = count($this->store->read($answerRun->id) ?? []);

        $this->assertThrows(fn () => $this->start($rows), AnswerNotConfirmed::class);

        $this->assertCount($items, $this->store->read($answerRun->id) ?? []);

        foreach ($rows as $row) {
            $row->refresh();
            $expected = [AiToolCallStatus::AwaitingConfirmation, null, null];
            $this->assertSame($expected, [$row->status, $row->answer, $row->finished_at]);
        }
    }

    /** H2: 「やめる」は確認待ちのすべてに理由を付けない断りと中断の印(道具の途中の形)を書き、行を断られた(答え = やめる)にする(T8) */
    public function test_a_reject_answer_rejects_every_awaiting_call(): void
    {
        $rows = $this->asked(['1', '2']);
        $this->answerRun(AiConversationAnswer::Reject);

        $this->assertNull($this->start($rows));

        foreach ($rows as $row) {
            $row->refresh();
            $this->assertSame(
                [AiToolCallStatus::Rejected, AiToolCallAnswer::Reject, $this->answerRun?->id],
                [$row->status, $row->answer, $row->answer_run_id],
            );
        }

        $items = array_slice($this->store->read($this->answerRun->id ?? 0) ?? [], -3);
        $this->assertSame(['c1', 'c2'], [$items[0]->content['call_id'], $items[1]->content['call_id']]);
        $this->assertSame(AiInstructionTemplate::ToolUseRejected->value, $items[0]->content['template']);
        $this->assertSame(ConversationItemType::Interruption, $items[2]->type);
        $this->assertTrue($items[2]->content['during_tool']);
    }

    /** H1: 「実行」は承認した件と、モデルの元の引数の先に動かす呼び出しを呼び出しの順に返す */
    public function test_an_approve_answer_returns_the_calls_in_order(): void
    {
        $rows = $this->asked(['1', '2']);
        $this->answerRun();

        $started = $this->start($rows);

        $this->assertNotNull($started);
        [$answer, $calls] = $started;
        $this->assertSame(['c1', 'c2'], array_keys($answer->rows));
        $this->assertSame(['c1', 'c2'], array_map(fn (CoreToolCall $call): string => $call->callId, $calls));
        $this->assertSame('1', $calls[0]->arguments['values']['title'] ?? null);
    }

    /**
     * 止めたで終える時に確認待ちで残った行: その実行の確認待ち(T5)、「実行」の対象(T7)、「やめる」の対象(断りを書く前に会話が無いで止めた = T8)
     *
     * @return array<string, array{AiConversationAnswer|null, AiToolCallStatus, AiToolCallAnswer|null}>
     */
    public static function stoppedCancels(): array
    {
        return [
            'その実行の確認待ち' => [null, AiToolCallStatus::Cancelled, null],
            '実行の答えの対象' => [AiConversationAnswer::Approve, AiToolCallStatus::Cancelled, AiToolCallAnswer::Approve],
            'やめるの答えの対象' => [AiConversationAnswer::Reject, AiToolCallStatus::Rejected, AiToolCallAnswer::Reject],
        ];
    }

    #[DataProvider('stoppedCancels')]
    public function test_a_stopped_run_cancels_its_awaiting_rows(
        ?AiConversationAnswer $answer,
        AiToolCallStatus $status,
        ?AiToolCallAnswer $expected,
    ): void {
        $rows = $this->asked(['1']);
        $stopped = $this->run;

        if ($answer !== null) {
            $this->answerRun($answer);
            $stopped = $this->answerRun ?? $this->fail('答えの実行が無い');
        }

        app(EndAwaitingToolCalls::class)->execute($stopped);

        $row = $rows[0]->refresh();
        $this->assertSame([$status, $expected], [$row->status, $row->answer]);
        $this->assertSame($expected === null ? null : $stopped->id, $row->answer_run_id);
    }

    /**
     * 作るの呼び出しを問うに通し、その呼び出しを持つモデルの文を会話の保存に足す(答えの実行の始めの確かめが引くため)。
     *
     * @param  list<string>  $titles
     * @return list<AiToolCall>
     */
    private function asked(array $titles): array
    {
        $calls = array_map(fn (string $title): array => ['ai_create_record', [
            'module' => 'm_tasks',
            'values' => ['title' => $title],
        ]], $titles);
        $rows = $this->ask($calls);
        $blocks = array_map(
            fn (array $call, int $index): array => ConversationItem::toolCallBlock(
                'c'.($index + 1),
                $call[0],
                $call[1],
            ),
            $calls,
            array_keys($calls),
        );
        $this->store->append($this->run->id, [ConversationItem::modelText('r1', $blocks)]);

        return $rows;
    }

    /**
     * @param  list<AiToolCall>  $rows
     * @return array{AnswerCalls, list<CoreToolCall>}|null
     */
    private function start(array $rows): ?array
    {
        $run = $this->answerRun ?? $this->fail('答えの実行が無い');
        $history = $this->store->read($this->run->id) ?? [];
        Exceptions::fake();

        $definition = $run->agentDefinition()->sole();

        return app(AnswerRunStart::class)->start($run, $this->store, $definition, $rows, $history);
    }

    /**
     * @param  list<AiToolCall>  $rows
     * @return array{RunTools, RunToolStep}
     */
    private function approvedTools(array $rows): array
    {
        $granted = $this->answerRun();
        $run = $this->answerRun ?? $this->fail('答えの実行が無い');
        $keyed = array_combine(array_map(fn (AiToolCall $row): string => $row->call_id, $rows), $rows);
        $texts = AiToolCallText::query()->whereKey(array_map(fn (AiToolCall $row): int => $row->id, $rows))->get();
        $answer = new AnswerCalls($run->agentDefinition()->sole(), $keyed, $texts->keyBy('ai_tool_call_id')->all());
        $cursor = new RunToolStep($run, $granted, $this->store, $answer);

        $stages = app(ToolCallStages::class);

        return [new RunTools($cursor, $stages, app(ToolCallWrites::class), app(RunToolChildren::class)), $cursor];
    }

    private function notifier(RunToolStep $cursor): RunNotifier
    {
        $run = $this->answerRun ?? $this->fail('答えの実行が無い');
        $progress = new RunProgress($run->id, 60, new RunClock(CarbonImmutable::now(), 60));

        return new RunNotifier(
            $run->id,
            $this->store,
            new RunChainCalls,
            app(StepRecorder::class),
            $progress,
            $cursor,
            app(ToolCallRows::class),
        );
    }
}
