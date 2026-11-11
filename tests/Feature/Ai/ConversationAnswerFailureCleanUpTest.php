<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\CleanUpFailedConversation;
use App\Actions\Ai\FailRun;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallStatus;
use App\Jobs\Ai\RunPromptJob;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiToolCall;
use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\TransactionalTestCase;

/**
 * 失敗で終わる会話の型の実行の後始末の、前の実行の確認待ちの扱い(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.8 の
 * 「その実行が答える対象の確認待ちの道具の行」、設計/AI/実行の記録.md 2.4 の T6)と作りの誤り。表の全体と失敗の経路は
 * {@see ConversationFailureCleanUpTest}。
 */
final class ConversationAnswerFailureCleanUpTest extends TransactionalTestCase
{
    use BuildsFailingConversations;
    use UsesTestConversationKinds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestConversationKind();
    }

    /** 答えの実行が答え始める前に失敗すると、前の実行の確認待ちはすべて取り消しになる(2.4 の T6) */
    public function test_an_answer_run_failing_before_answering_cancels_every_awaiting_call(): void
    {
        [$previous, $answer] = $this->answeredPair();
        $step = AiRunStep::factory()->succeeded()->create(['ai_run_id' => $previous->id]);
        $rows = [];

        foreach ([1, 2, 3] as $position) {
            $rows["{$position} 件目"] = AiToolCall::factory()->inStep($step)->asked()->create();
        }

        app(FailRun::class)->execute($answer->id, AiRunErrorCode::Provider, new RuntimeException('誤り'));

        $expected = ['1 件目' => 'cancelled', '2 件目' => 'cancelled', '3 件目' => 'cancelled'];
        $this->assertSame($expected, $this->statuses($rows));
    }

    /** 2 件目を書く間に worker が落ちると、2 件目は結果不明、3 件目は取り消しになり、書き終えた 1 件目は済みのまま(2.4 の T6・T7) */
    public function test_an_answer_run_failing_while_writing_the_second_call(): void
    {
        [$previous, $answer] = $this->answeredPair();
        $step = AiRunStep::factory()->succeeded()->create(['ai_run_id' => $previous->id]);
        $calls = AiToolCall::factory()->inStep($step);
        $rows = [
            '1 件目' => $calls->answered(AiToolCallAnswer::Approve, AiToolCallStatus::Done, $answer)->create(),
            '2 件目' => $calls->answered(AiToolCallAnswer::Approve, AiToolCallStatus::Running, $answer)->create(),
            '3 件目' => $calls->asked()->create(),
        ];

        (new RunPromptJob($answer->id, 100))->failed(new RuntimeException('worker が落ちた'));

        $this->assertSame(['1 件目' => 'done', '2 件目' => 'unknown', '3 件目' => 'cancelled'], $this->statuses($rows));
    }

    /**
     * 答えでない文の実行は答える対象を持たないので、失敗しても前の実行の確認待ちは前の実行のものとして残る(自分に属する確認待ちは取り消す)。
     */
    public function test_a_text_run_failing_leaves_the_awaiting_calls_of_the_previous_run(): void
    {
        [$previous, $text] = $this->answeredPair(null);
        $previousStep = AiRunStep::factory()->succeeded()->create(['ai_run_id' => $previous->id]);
        $step = AiRunStep::factory()->succeeded()->create(['ai_run_id' => $text->id]);
        $rows = [
            '前の実行の確認待ち' => AiToolCall::factory()->inStep($previousStep)->asked()->create(),
            '文の実行の確認待ち' => AiToolCall::factory()->inStep($step)->asked()->create(),
        ];

        app(FailRun::class)->execute($text->id, AiRunErrorCode::Provider, new RuntimeException('誤り'));

        $this->assertSame(['前の実行の確認待ち' => 'awaiting_confirmation', '文の実行の確認待ち' => 'cancelled'], $this->statuses($rows));
    }

    /**
     * 失敗で終わる会話の型の実行でない行(処理中のままの会話の型・失敗した文章の型)。
     *
     * @return array<string, array{Closure(self): AiRun}>
     */
    public static function runsThatAreNotFailedConversations(): array
    {
        return [
            '処理中の会話の型' => [fn (self $test): AiRun => $test->conversationRun([
                'status' => AiRunStatus::Running,
                'started_at' => now(),
            ])],
            '失敗した文章の型' => [fn (): AiRun => AiRun::factory()->failed()->create()],
        ];
    }

    /**
     * 後始末は失敗で終わる会話の型の実行だけに当てる(ほかの行を渡すのは作りの誤り)。
     *
     * @param  Closure(self): AiRun  $run
     */
    #[DataProvider('runsThatAreNotFailedConversations')]
    public function test_cleaning_up_a_run_that_is_not_a_failed_conversation_is_a_logic_error(Closure $run): void
    {
        $this->expectException(LogicException::class);

        app(CleanUpFailedConversation::class)->execute($run($this));
    }
}
