<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\StopConversationRun;
use App\Actions\Ai\Api\StopOutcome;
use App\Actions\Ai\Core\StoredTexts;
use App\Actions\Ai\Instructions\InstructionBuilder;
use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Enums\Ai\ConversationItemType;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Database\Factories\Ai\AiRunFactory;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\FakeConversationStore;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Support\TransactionalTestCase;

/**
 * 止める口(docs の 設計/AI/公開の口.md 3 節の止めるの行、9.5、無しと作りの誤りの優先 = 設計/AI/エージェントの実行/口と契約.md 9.12)。
 * 表の行: 無い・ほかの人の実行・ゲスト = 無し(会話の型でなくても無しが先)/ 自分の会話の型でない実行 = 作りの誤り / 終わった = 何もしない /
 * 処理中 = 印(あれば書き直さない)/ 待ちの文の実行 = 止めた(利用者)と中断の印 / 待ちの答えの実行 = 作りの誤り(書く道具の承認と一緒に扱う)。
 */
final class StopConversationRunTest extends TransactionalTestCase
{
    use UsesTestConversationKinds;

    private User $requester;

    private FakeConversationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
        FakeConversationStore::createTables();
        $this->store = new FakeConversationStore;
        $this->requester = User::factory()->create();
    }

    public function test_a_queued_run_is_stopped_with_an_interruption(): void
    {
        $previous = $this->conversationRun(['status' => AiRunStatus::Failed], fn ($factory) => $factory->failed());
        $run = $this->conversationRun(['previous_run_id' => $previous->id]);
        $this->store->continueConversation($this->store->startConversation($previous->id), $run->id);

        $outcome = $this->stop($run->id);

        $saved = AiRun::query()->findOrFail($run->id);
        $this->assertSame(StopOutcome::StoppedWhileQueued, $outcome);
        $this->assertSame([AiRunStatus::Stopped, AiRunStopReason::User], [$saved->status, $saved->stop_reason]);
        $this->assertNotNull($saved->finished_at);
        $items = $this->store->read($run->id) ?? [];
        $this->assertSame([ConversationItemType::Interruption], array_map(static fn ($item) => $item->type, $items));
        // 書く時に組んだ文と会話の印の形の一覧の印を持つ(設計/AI/会話の中核.md 13.2)
        $builder = app(InstructionBuilder::class);
        $rendered = [
            'text' => $builder->template(AiInstructionTemplate::Interrupted, []),
            'forms_id' => StoredTexts::formsId($builder->conversationMarkerForms()),
        ];
        $this->assertSame(['during_tool' => false, 'rendered' => $rendered], $items[0]->content);
        $this->assertSame([], $this->conversationKind->completed);
    }

    /** 会話が無くても止める(書く先が無いので印は書かない) */
    public function test_a_queued_run_without_a_conversation_is_stopped(): void
    {
        $run = $this->conversationRun();

        $this->assertSame(StopOutcome::StoppedWhileQueued, $this->stop($run->id));
        $this->assertSame(AiRunStatus::Stopped, AiRun::query()->findOrFail($run->id)->status);
    }

    /** 処理中は印を書き、2 回目は書き直さない */
    public function test_a_running_run_is_marked_once(): void
    {
        $run = $this->conversationRun([], fn ($factory) => $factory->running());

        $outcomes = [$this->stop($run->id)];
        $first = AiRun::query()->findOrFail($run->id)->stop_requested_at;
        $this->travel(5)->seconds();
        $outcomes[] = $this->stop($run->id);

        $saved = AiRun::query()->findOrFail($run->id);
        $this->assertSame([StopOutcome::Marked, StopOutcome::Marked], $outcomes);
        $this->assertNotNull($first);
        $this->assertEquals($first, $saved->stop_requested_at);
        $this->assertSame(AiRunStatus::Running, $saved->status);
    }

    public function test_a_finished_run_is_left_alone(): void
    {
        $run = $this->conversationRun([], fn ($factory) => $factory->failed());

        $this->assertSame(StopOutcome::AlreadyFinished, $this->stop($run->id));
        $this->assertNull(AiRun::query()->findOrFail($run->id)->stop_requested_at);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nobodies(): array
    {
        return [
            '無い実行' => ['missing'],
            'ほかの人の実行' => ['other'],
            'ゲスト' => ['guest'],
            'ほかの人の文章の実行' => ['other-prompt'],
        ];
    }

    /** 依頼者の実行でない・無いは無し(会話の型でない実行でも無しが先) */
    #[DataProvider('nobodies')]
    public function test_nothing_for_runs_not_of_the_requester(string $case): void
    {
        $other = User::factory()->create();
        $run = match ($case) {
            'other-prompt' => AiRun::factory()->create(['requester_user_id' => $other->id]),
            default => $this->conversationRun(['requester_user_id' => $other->id]),
        };
        $runId = $case === 'missing' ? $run->id + 1000 : $run->id;

        $this->assertNull($this->stop($runId, $case === 'guest' ? null : $this->requester->id));
        $this->assertSame(AiRunStatus::Queued, AiRun::query()->findOrFail($run->id)->status);
    }

    public function test_an_own_run_of_another_type_is_a_construction_error(): void
    {
        $run = AiRun::factory()->create(['requester_user_id' => $this->requester->id]);
        $this->assertNotSame(AiKindType::Conversation, $run->kind_type);

        $this->expectException(LogicException::class);
        $this->stop($run->id);
    }

    public function test_a_queued_answer_run_is_not_handled_yet(): void
    {
        $previous = $this->conversationRun([], fn ($factory) => $factory->failed());

        $answer = $this->conversationRun([
            'previous_run_id' => $previous->id,
            'answer' => AiConversationAnswer::Reject,
        ]);

        $this->expectException(LogicException::class);
        $this->stop($answer->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  (callable(AiRunFactory): AiRunFactory)|null  $state
     */
    private function conversationRun(array $attributes = [], ?callable $state = null): AiRun
    {
        $factory = AiRun::factory()->conversation();
        $factory = $state === null ? $factory : $state($factory);

        return $factory->create([
            'kind' => RecordingConversationKind::CODE,
            'requester_user_id' => $this->requester->id,
            ...array_diff_key($attributes, ['status' => true]),
        ]);
    }

    private function stop(int $runId, ?int $userId = null): ?StopOutcome
    {
        $context = new AccessContext(
            func_num_args() === 1 ? $this->requester->id : $userId,
            null,
            null,
            CarbonImmutable::today(AccessContext::TIMEZONE),
            AccessContext::LOCALE_CODE,
        );

        return app(StopConversationRun::class)->execute($context, $runId);
    }
}
