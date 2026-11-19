<?php

namespace Tests\Feature\Ai\Conversation;

use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\AiToolWriteDenial;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiRunText;
use App\Models\Ai\AiToolCall;
use App\Models\Ai\AiToolCallText;
use App\Models\Records\RecordModule;
use App\Queries\Ai\Api\RunQuery;
use App\Queries\Ai\Api\RunView;
use App\Queries\Ai\BudgetMessages;
use App\Queries\Authz\Api\Requester;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の型の実行の読み取り(docs の 設計/AI/エージェントの実行/口と契約.md 9.12 の実行の読み取り、文 = 設計/AI/データと失敗と検査.md 7 節)。
 * 見られるのは依頼者だけ。止めたの文は理由ごと、失敗の文は失敗(設定)・失敗(確認)とほかで分かれる。
 */
final class ConversationRunReadTest extends TransactionalTestCase
{
    public function test_the_conversation_part_is_returned_to_the_requester(): void
    {
        RecordModule::factory()->create(['code' => 'customer', 'name' => '顧客']);
        $run = AiRun::factory()->conversation()->running()->create([
            'kind' => RecordingConversationKind::CODE,
            'compacted' => true,
            'retry_wait_seconds' => 12,
            'retry_attempt' => 2,
        ]);
        $step = AiRunStep::factory()->create(['ai_run_id' => $run->id]);
        $partial = [['step_id' => $step->id, 'text' => '途中']];
        AiRunText::query()->create(['ai_run_id' => $run->id, 'partial_text' => $partial]);
        $call = AiToolCall::factory()->inStep($step)->create([
            'tool_name' => 'read_record',
            'records' => [['module' => 'customer', 'id' => 7], ['module' => 'gone', 'id' => 8]],
        ]);
        AiToolCallText::factory()->create([
            'ai_tool_call_id' => $call->id,
            'arguments' => ['id' => 7],
            'result_text' => '結果',
            'record_names' => [['module' => 'customer', 'id' => 7, 'name' => '山田商事']],
        ]);

        $conversation = $this->show($run->requester_user_id, $run->id)?->conversation;

        $this->assertNotNull($conversation);
        $this->assertSame([['step_id' => $step->id, 'text' => '途中']], $conversation->partialText);
        $this->assertSame(['seconds' => 12, 'attempt' => 2], $conversation->retryWait);
        $this->assertTrue($conversation->compacted);
        $tool = $conversation->toolCalls[0] ?? null;
        $this->assertSame(
            [$run->id, 'read_record', AiToolCallStatus::Done, ['id' => 7], '結果'],
            [$tool?->runId, $tool?->name, $tool?->status, $tool?->arguments, $tool?->result],
        );
        $this->assertSame([
            ['module' => 'customer', 'module_name' => '顧客', 'id' => 7, 'name' => '山田商事'],
            ['module' => 'gone', 'module_name' => null, 'id' => 8, 'name' => null],
        ], $conversation->records);
        $this->assertNull($this->show($run->requester_user_id + 1000, $run->id));
    }

    /** 原文の期限で消えた後は、引数・結果・表示の名前を返さない(業務モジュールと id と、行の値の書く時の断りの理由は返す) */
    public function test_expired_texts_leave_the_module_and_id(): void
    {
        $run = AiRun::factory()->conversation()->running()->create(['kind' => RecordingConversationKind::CODE]);
        $step = AiRunStep::factory()->create(['ai_run_id' => $run->id]);
        AiToolCall::factory()->inStep($step)->create(['records' => [['module' => 'customer', 'id' => 7]]]);
        AiToolCall::factory()->inStep($step)->answered(AiToolCallAnswer::Approve, AiToolCallStatus::Rejected)->create();

        $conversation = $this->show($run->requester_user_id, $run->id)?->conversation;

        $this->assertNull($conversation?->toolCalls[0]->arguments ?? null);
        $this->assertSame(AiToolWriteDenial::NoPermission, $conversation?->toolCalls[1]->writeDenialReason ?? null);
        $expected = [['module' => 'customer', 'module_name' => null, 'id' => 7, 'name' => null]];
        $this->assertSame($expected, $conversation?->records);
        $this->assertSame([], $conversation->partialText);
        $this->assertNull($conversation->retryWait);
    }

    /**
     * @return array<string, array{AiRunStopReason, string|null, string}>
     */
    public static function stops(): array
    {
        return [
            '利用者' => [AiRunStopReason::User, null, RunView::STOPPED_BY_USER_MESSAGE],
            '時間の上限' => [AiRunStopReason::TimeLimit, null, RunView::STOPPED_AT_LIMIT_MESSAGE],
            '回数の上限' => [AiRunStopReason::TurnLimit, null, RunView::STOPPED_AT_LIMIT_MESSAGE],
            '長さ' => [AiRunStopReason::Length, null, RunView::STOPPED_FOR_LENGTH_MESSAGE],
            '費用の上限(日)' => [AiRunStopReason::Budget, 'day', (string) BudgetMessages::of('default', 'day')],
            '費用の上限(月)' => [AiRunStopReason::Budget, 'month', (string) BudgetMessages::of('default', 'month')],
            '費用の上限(累計)' => [AiRunStopReason::Budget, 'total', (string) BudgetMessages::of('default', 'total')],
        ];
    }

    #[DataProvider('stops')]
    public function test_the_stop_message_follows_the_reason(
        AiRunStopReason $reason,
        ?string $period,
        string $message,
    ): void {
        $run = AiRun::factory()->conversation()->create([
            'kind' => RecordingConversationKind::CODE,
            'status' => AiRunStatus::Stopped,
            'stop_reason' => $reason,
            'stop_budget_period' => $period,
            'finished_at' => now(),
        ]);

        $view = $this->show($run->requester_user_id, $run->id);

        $this->assertSame([$message, null], [$view?->stopMessage, $view?->failureMessage]);
        $conversation = $view?->conversation;
        $this->assertSame([$reason, $period], [$conversation?->stopReason, $conversation?->stopBudgetPeriod]);
    }

    /**
     * @return array<string, array{AiRunErrorCode, string}>
     */
    public static function failures(): array
    {
        return [
            '設定' => [AiRunErrorCode::Setting, RunView::SETTING_FAILURE_MESSAGE],
            '確認' => [AiRunErrorCode::Confirmation, RunView::CONFIRMATION_FAILURE_MESSAGE],
            'ほか' => [AiRunErrorCode::Provider, RunView::FAILURE_MESSAGE],
        ];
    }

    #[DataProvider('failures')]
    public function test_the_failure_message_follows_the_error_code(AiRunErrorCode $code, string $message): void
    {
        $run = AiRun::factory()->conversation()->failed($code)->create(['kind' => RecordingConversationKind::CODE]);

        $view = $this->show($run->requester_user_id, $run->id);

        $this->assertSame([$message, null], [$view?->failureMessage, $view?->stopMessage]);
    }

    private function show(int $userId, int $runId): ?RunView
    {
        $today = CarbonImmutable::today('Asia/Tokyo');

        return app(RunQuery::class)->show(new Requester($userId, null), $runId, $today, 'ja');
    }
}
