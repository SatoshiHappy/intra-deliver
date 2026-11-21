<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationRequested;
use App\Actions\Ai\Api\RequestConversation;
use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\CompletingRun;
use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\ConversationItemDisplay;
use App\Enums\Ai\ConversationItemType;
use App\Jobs\Ai\RunConversationJob;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Models\Master\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\RecordingPromptKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Feature\Ai\UsesTestPromptKinds;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の依頼の 2 つの口(docs の 設計/AI/エージェントの実行/口と契約.md 9.12、表 = 設計/AI/公開の口.md 3 節の B1・B7〜B14・B16 と「断る理由が
 * 重なったとき」、承認待ちへの理由つきの断り = 設計/AI/エージェントの実行/README.md 9.3 の H3)。
 */
final class RequestConversationTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use RequestsConversations;
    use UsesTestConversationKinds;
    use UsesTestPromptKinds;

    private User $requester;

    private AiAgentDefinition $definition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        $this->registerTestConversationKind();
        $this->requester = User::factory()->create();
        $this->definition = AiAgentDefinition::factory()->create(['tools' => [], 'knowledge' => []]);
        Queue::fake();
    }

    /** B14(前の実行が無い): 待ちの実行と利用者の文を書き、会話の専用のキューにジョブを入れ、利用者の文の項目を返す */
    public function test_the_first_text_writes_a_queued_run_and_returns_the_user_text(): void
    {
        $requested = $this->send('会議をまとめて', null, true);

        $run = AiRun::query()->findOrFail($requested->runId);
        $this->assertSame([AiRunStatus::Queued, $this->definition->id, null, true, null], [
            $run->status, $run->agent_definition_id, $run->previous_run_id, $run->wants_title, $run->answer,
        ]);
        $this->assertSame('会議をまとめて', AiRunText::query()->findOrFail($run->id)->user_text);
        $this->assertSame([ConversationItemType::UserText], array_map(fn ($item) => $item->type, $requested->items));
        $connection = config('ai_runs.conversation.queue_connection');
        Queue::assertPushed(
            RunConversationJob::class,
            fn (RunConversationJob $job): bool => $job->runId === $run->id && $job->connection === $connection,
        );
    }

    /** B14(前の実行が確認待ち)= H3: 文は承認待ちへの断り(理由は最初の結果だけ)になり、前の実行の確認待ちの行は断られた(答えずに次の文) */
    public function test_a_text_after_an_awaiting_run_rejects_the_awaiting_calls_with_the_text(): void
    {
        $previous = $this->previous(AiRunStatus::AwaitingConfirmation);
        $rows = $this->awaitingRows($previous, 2);

        $requested = $this->send('やっぱり別の件', $previous->id);

        $this->assertSame(['c1', 'c2'], array_map(fn ($item) => $item->content['call_id'], $requested->items));
        $first = $requested->items[0];
        $this->assertSame(AiInstructionTemplate::ToolUseRejectedWithReason->value, $first->content['template']);
        $this->assertSame(ConversationItemDisplay::ReasonAsUserText, $first->display);
        $this->assertSame(AiInstructionTemplate::ToolUseRejected->value, $requested->items[1]->content['template']);

        foreach ($rows as $row) {
            $row->refresh();
            $this->assertSame([AiToolCallStatus::Rejected, AiToolCallAnswer::NextText, $requested->runId], [
                $row->status, $row->answer, $row->answer_run_id,
            ]);
        }
    }

    /** B16: 確認に答える依頼は答えの実行を書き(題を求めない)、項目を返さない。費用では断らない */
    public function test_an_answer_writes_an_answer_run_without_items_even_over_the_budget(): void
    {
        $previous = $this->previous(AiRunStatus::AwaitingConfirmation);
        $this->overBudget();

        $requested = $this->answer(AiConversationAnswer::Reject, $previous->id);

        $run = AiRun::query()->findOrFail($requested->runId);
        $written = [$run->answer, $run->previous_run_id, $run->wants_title];
        $this->assertSame([AiConversationAnswer::Reject, $previous->id, false], $written);
        $this->assertSame([], $requested->items);
        $this->assertNull(AiRunText::query()->find($run->id));
    }

    /** B1: ゲストは 403 */
    public function test_a_guest_is_forbidden(): void
    {
        $this->expectException(AuthorizationException::class);

        app(RequestConversation::class)->send($this->guestContext(), $this->textRequest('文', null));
    }

    /**
     * 作りの誤り(B7・B8・B10 と、口と型の違う種類)。
     *
     * @return array<string, array{Closure(self): mixed}>
     */
    public static function constructionErrors(): array
    {
        return [
            '文章の種類を会話の口に' => [fn (self $test) => $test->send('文', null, false, RecordingPromptKind::CODE)],
            '登録の無い会話の型の種類' => [function (self $test) {
                $kinds = (array) config('ai_runs.kinds');
                $kinds['test.unregistered'] = self::conversationKindSettings();
                config(['ai_runs.kinds' => $kinds]);

                return $test->send('文', null, false, 'test.unregistered');
            }],
            '完了の受け手の中(B8)' => [fn (self $test) => app(CompletingRun::class)->during(1, function () use ($test): void {
                $test->send('文', null);
            })],
            '定義が無い(B10)' => [fn (self $test) => $test->send('文', null, false, null, $test->definition->id + 1000)],
            '前の実行が無い(B10)' => [fn (self $test) => $test->send('文', 999999)],
            '前の実行が依頼者のでない(B10)' => [fn (self $test) => $test->send('文', $test->previous(
                AiRunStatus::Succeeded,
                User::factory()->create()->id,
            )->id)],
            '前の実行が会話の型でない(B10)' => [fn (self $test) => $test->send('文', AiRun::factory()->create([
                'requester_user_id' => $test->requester->id,
            ])->id)],
        ];
    }

    /** @param  Closure(self): mixed  $request */
    #[DataProvider('constructionErrors')]
    public function test_construction_errors_are_logic_exceptions(Closure $request): void
    {
        $this->expectException(LogicException::class);

        $request($this);
    }

    /**
     * 前の実行の 422(B11〜B13)と重なったときの順(B11 → B12 → B13)。
     *
     * @return array<string, array{AiRunStatus, bool, bool, string}>
     */
    public static function previousRefusals(): array
    {
        return [
            '文・待ち(B11)' => [AiRunStatus::Queued, false, false, RunRequestDeclined::PREVIOUS_RUNNING],
            '答え・処理中(B11)' => [AiRunStatus::Running, true, false, RunRequestDeclined::PREVIOUS_RUNNING],
            '答え・成功(B12)' => [AiRunStatus::Succeeded, true, false, RunRequestDeclined::CONFIRMATION_GONE],
            '答え・止めた(B12)' => [AiRunStatus::Stopped, true, false, RunRequestDeclined::CONFIRMATION_GONE],
            '答え・失敗(B12)' => [AiRunStatus::Failed, true, false, RunRequestDeclined::CONFIRMATION_GONE],
            '文・続きがある(B13)' => [AiRunStatus::Succeeded, false, true, RunRequestDeclined::CONTINUED],
            '答え・続きがある(B13)' => [AiRunStatus::AwaitingConfirmation, true, true, RunRequestDeclined::CONTINUED],
            '処理中で続きがある(B11 が先)' => [AiRunStatus::Running, false, true, RunRequestDeclined::PREVIOUS_RUNNING],
            '答え・成功で続きがある(B12 が先)' => [AiRunStatus::Succeeded, true, true, RunRequestDeclined::CONFIRMATION_GONE],
        ];
    }

    #[DataProvider('previousRefusals')]
    public function test_the_previous_run_refusals_follow_their_order(
        AiRunStatus $status,
        bool $answers,
        bool $continued,
        string $reason,
    ): void {
        $previous = $this->previous($status);

        if ($continued) {
            AiRun::factory()->conversation()->create([
                'previous_run_id' => $previous->id,
                'requester_user_id' => $this->requester->id,
            ]);
        }

        $declined = $this->declined(fn () => $answers
            ? $this->answer(AiConversationAnswer::Approve, $previous->id)
            : $this->send('文', $previous->id));

        $this->assertSame($reason, $declined?->reason);
    }

    /** 鍵を経ない書き込みで前の実行の一意の制約に当たったら B13 と同じ 422(保存点で戻し、呼び手のトランザクションは使える) */
    public function test_a_unique_violation_is_declined_and_the_caller_transaction_stays_usable(): void
    {
        $previous = $this->previous(AiRunStatus::Succeeded);
        $inserting = false;
        AiRun::creating(function (AiRun $run) use ($previous, &$inserting): void {
            if ($run->previous_run_id === $previous->id && ! $inserting) {
                $inserting = true;
                AiRun::factory()->conversation()->create([
                    'previous_run_id' => $previous->id,
                    'requester_user_id' => $this->requester->id,
                ]);
            }
        });

        $declined = $this->declined(fn () => $this->send('文', $previous->id));

        $this->assertSame(RunRequestDeclined::CONTINUED, $declined?->reason);
        $this->assertSame(0, AiRun::query()->where('previous_run_id', $previous->id)->count());
        $this->assertTrue($previous->refresh()->exists);
    }

    /**
     * 定義の 422(外した後の大きさ。文・確認に答える(やめるを含む)の両方)・長さの 422(文だけ。境目とその両側)・上限(文だけ)。
     *
     * @return array<string, array{Closure(self): ConversationRequested, string|null}>
     */
    public static function laterRefusals(): array
    {
        $tooLarge = function (self $test): void {
            $test->recordModule('m_known', []);
            $test->definition->update(['tools' => ['ai_read_record'], 'knowledge' => ['m_known']]);
            config(['ai_runs.conversation.purposes.conversation.context_window_tokens' => 1]);
        };

        return [
            '文・大きさ' => [function (self $test) use ($tooLarge) {
                $tooLarge($test);

                return $test->send('文', null);
            }, RunRequestDeclined::SETTING],
            'やめる・大きさ' => [function (self $test) use ($tooLarge) {
                $tooLarge($test);

                $previous = $test->previous(AiRunStatus::AwaitingConfirmation);

                return $test->answer(AiConversationAnswer::Reject, $previous->id);
            }, RunRequestDeclined::SETTING],
            '文が上限ちょうど' => [fn (self $test) => $test->send(str_repeat('文', 1000), null), null],
            '文が上限より 1 長い' => [
                fn (self $test) => $test->send(str_repeat('文', 1001), null),
                RunRequestDeclined::TOO_LONG,
            ],
            '文・上限を超えた' => [function (self $test) {
                $test->overBudget();

                return $test->send('文', null);
            }, RunRequestDeclined::BUDGET],
        ];
    }

    /** @param  Closure(self): ConversationRequested  $request */
    #[DataProvider('laterRefusals')]
    public function test_the_definition_length_and_budget_refusals(Closure $request, ?string $reason): void
    {
        $declined = $this->declined(fn () => $request($this));

        $this->assertSame($reason, $declined?->reason);
    }
}
