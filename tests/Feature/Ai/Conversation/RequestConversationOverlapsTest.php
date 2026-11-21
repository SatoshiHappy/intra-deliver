<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\RunRequestDeclined;
use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiToolCallStatus;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use Closure;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Ai\UsesTestConversationKinds;
use Tests\Feature\Ai\UsesTestPromptKinds;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の依頼の口の断る理由の重なり(docs の 設計/AI/公開の口.md 3 節の「断る理由が重なったとき」)と、前の実行が確認待ちのときに断る依頼が
 * 道具の行を変えないこと(設計/AI/エージェントの実行/口と契約.md 9.12)。
 */
final class RequestConversationOverlapsTest extends TransactionalTestCase
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

    /**
     * 重なった理由のうち先の 1 つ(前の実行 → 定義 → 長さ → 上限)。
     *
     * @return array<string, array{Closure(self): mixed, string}>
     */
    public static function overlaps(): array
    {
        $long = str_repeat('文', 1001);

        return [
            '前の実行が待ち・大きすぎ・長すぎ' => [function (self $test) use ($long) {
                $previous = $test->previous(AiRunStatus::Queued);
                $test->tooLarge();

                return $test->send($long, $previous->id);
            }, RunRequestDeclined::PREVIOUS_RUNNING],
            '大きすぎ・長すぎ・上限を超えた' => [function (self $test) use ($long) {
                $test->tooLarge();
                $test->overBudget();

                return $test->send($long, null);
            }, RunRequestDeclined::SETTING],
            '長すぎ・上限を超えた' => [function (self $test) use ($long) {
                $test->overBudget();

                return $test->send($long, null);
            }, RunRequestDeclined::TOO_LONG],
            '答え・前の実行が成功・大きすぎ' => [function (self $test) {
                $previous = $test->previous(AiRunStatus::Succeeded);
                $test->tooLarge();

                return $test->answer(AiConversationAnswer::Approve, $previous->id);
            }, RunRequestDeclined::CONFIRMATION_GONE],
        ];
    }

    /** @param  Closure(self): mixed  $request */
    #[DataProvider('overlaps')]
    public function test_the_first_reason_in_the_order_wins(Closure $request, string $reason): void
    {
        $this->assertSame($reason, $this->declined(fn () => $request($this))?->reason);
    }

    /**
     * 前の実行が確認待ちの文を送る依頼が断られる理由。
     *
     * @return array<string, array{Closure(self, AiRun): void, string}>
     */
    public static function awaitingRefusals(): array
    {
        return [
            '定義の 422' => [fn (self $test) => $test->tooLarge(), RunRequestDeclined::SETTING],
            '長さの 422' => [fn () => null, RunRequestDeclined::TOO_LONG],
            '上限の 422' => [fn (self $test) => $test->overBudget(), RunRequestDeclined::BUDGET],
            '一意の制約の 422' => [
                fn (self $test, AiRun $previous) => $test->continuedOutsideTheLock($previous),
                RunRequestDeclined::CONTINUED,
            ],
        ];
    }

    /** @param  Closure(self, AiRun): void  $arrange */
    #[DataProvider('awaitingRefusals')]
    public function test_a_declined_text_leaves_the_awaiting_calls_as_they_are(Closure $arrange, string $reason): void
    {
        $previous = $this->previous(AiRunStatus::AwaitingConfirmation);
        $rows = $this->awaitingRows($previous, 2);
        $arrange($this, $previous);
        $text = $reason === RunRequestDeclined::TOO_LONG ? str_repeat('文', 1001) : '文';

        $this->assertSame($reason, $this->declined(fn () => $this->send($text, $previous->id))?->reason);

        foreach ($rows as $row) {
            $row->refresh();
            $kept = [$row->status, $row->answer, $row->answer_run_id];
            $this->assertSame([AiToolCallStatus::AwaitingConfirmation, null, null], $kept);
        }
    }

    private function tooLarge(): void
    {
        $this->recordModule('m_known', []);
        $this->definition->update(['tools' => ['ai_read_record'], 'knowledge' => ['m_known']]);
        config(['ai_runs.conversation.purposes.conversation.context_window_tokens' => 1]);
    }

    /** 鍵を経ない書き込みが、依頼の口の実行の記録の直前に同じ前の実行の続きを足す */
    private function continuedOutsideTheLock(AiRun $previous): void
    {
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
    }
}
