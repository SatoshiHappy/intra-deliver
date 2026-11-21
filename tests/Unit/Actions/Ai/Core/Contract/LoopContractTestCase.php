<?php

namespace Tests\Unit\Actions\Ai\Core\Contract;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\AppendNotice;
use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\CoreNotice;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\ExchangeNotice;
use App\Actions\Ai\Core\LoopEndingKind;
use App\Actions\Ai\Core\LoopFailure;
use App\Actions\Ai\Core\LoopStop;
use App\Actions\Ai\Core\NoticeReply;
use App\Actions\Ai\Core\StopKind;
use App\Actions\Ai\Core\StoredTexts;
use Laravel\Ai\Responses\Data\FinishReason;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\Actions\Ai\Core\Support\FakeStepGateway;

/**
 * ループの約束(docs の 設計/AI/エージェントの実行/README.md 9.1、終わりの理由の表 = 設計/AI/会話の中核.md 13.6、確かめの表 = 13.5):
 * 終わり方ごとの流れと、会話の保存に足す項目。両方の版で同じ結果になる所だけを置く(版で違う所は版ごとのテスト)。
 */
abstract class LoopContractTestCase extends CoreContractTestCase
{
    public function test_an_answer_without_calls_succeeds(): void
    {
        $outcome = $this->loop([FakeStepGateway::response('答え')]);

        $this->assertSame(LoopEndingKind::Succeeded, $outcome->ending->kind);
        $this->assertSame(['model_text', 'response_end'], $this->appended());
    }

    public function test_calls_run_and_the_next_turn_answers(): void
    {
        $outcome = $this->loop([
            FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1')]),
            FakeStepGateway::response('答え'),
        ]);

        $this->assertSame(LoopEndingKind::Succeeded, $outcome->ending->kind);
        $this->assertSame(['c1'], $this->tools->ran);
        $types = array_map(
            static fn ($item): string => $item instanceof ConversationItem ? $item->type->value : 'transient',
            $outcome->sentHistory,
        );
        $expected = ['user_text', 'model_text', 'response_end', 'tool_result', 'model_text', 'response_end'];
        $this->assertSame($expected, $types);
    }

    public function test_a_call_that_asks_ends_awaiting_confirmation(): void
    {
        $outcome = $this->loop([FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1', 'ask')])]);
        $ending = $outcome->ending;

        $this->assertSame([LoopEndingKind::AwaitingConfirmation, ['c1']], [$ending->kind, $ending->askingCallIds]);
    }

    /** 回数の上限の境(上限 2 で 2 回目の道具の後に止める。上限 3 なら 3 回目が答える) */
    public function test_the_turn_limit_stops_after_the_tools_of_the_last_turn(): void
    {
        $calls = FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1')]);
        $calls2 = FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c2')]);

        $stopped = $this->loop([$calls, $calls2], 2);
        $ending = $stopped->ending;
        $this->assertSame([LoopEndingKind::Stopped, LoopStop::TurnLimit], [$ending->kind, $ending->stop]);
        $appended = $this->appended();
        $this->assertSame('turn_limit', end($appended));

        $answered = $this->loop([$calls, $calls2, FakeStepGateway::response('答え')], 3);
        $this->assertSame(LoopEndingKind::Succeeded, $answered->ending->kind);
    }

    /**
     * 答えの実行は前の実行の回数を引き継ぐ(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.7)。先に動かす呼び出しの後は引き継いだ数の
     * 次の番号で比べる: 上限 2・引き継ぎ 2 は送らずに止め、引き継ぎ 1 なら 1 回送れ、その回の道具の後に止める。
     */
    public function test_an_answer_run_counts_on_from_the_turns_it_inherits(): void
    {
        $preRun = [new CoreToolCall('p1', 'search', [])];

        $stopped = $this->loop([], 2, preRun: $preRun, priorTurns: 2)->ending;
        $this->assertSame([LoopEndingKind::Stopped, LoopStop::TurnLimit], [$stopped->kind, $stopped->stop]);
        $appended = $this->appended();
        $this->assertSame('turn_limit', end($appended));
        $this->assertSame([], $this->gateway->sent);

        $answered = $this->loop([FakeStepGateway::response('答え')], 2, preRun: $preRun, priorTurns: 1);
        $this->assertSame(LoopEndingKind::Succeeded, $answered->ending->kind);

        $calls = FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1')]);
        $last = $this->loop([$calls], 2, preRun: $preRun, priorTurns: 1)->ending;
        $this->assertSame([LoopEndingKind::Stopped, LoopStop::TurnLimit], [$last->kind, $last->stop]);
    }

    /**
     * 先に動かす呼び出しの無い実行も、引き継いだ数で上限を超えていれば最初の送信の前に止める(確認待ちの実行に文を送った実行 = 9.7)。
     * 止める印と両方に当たれば止める印が先(道具の後の位置と同じ順)。
     */
    public function test_a_run_stops_before_its_first_send_when_the_inherited_turns_reach_the_limit(): void
    {
        $stopped = $this->loop([], 2, priorTurns: 2)->ending;
        $this->assertSame([LoopEndingKind::Stopped, LoopStop::TurnLimit], [$stopped->kind, $stopped->stop]);
        $appended = $this->appended();
        $this->assertSame('turn_limit', end($appended));
        $this->assertSame([], $this->gateway->sent);

        $answered = $this->loop([FakeStepGateway::response('答え')], 2, priorTurns: 1);
        $this->assertSame(LoopEndingKind::Succeeded, $answered->ending->kind);

        $answer = static fn (CheckRequest $request): ?CheckReply => $request->point === CheckPoint::AfterTools
            ? CheckReply::stop(StopKind::User)
            : null;
        $both = $this->loop([], 2, $answer, priorTurns: 2)->ending;
        $this->assertSame([LoopEndingKind::Stopped, LoopStop::User], [$both->kind, $both->stop]);
    }

    public function test_a_user_stop_after_reading_writes_the_interruption(): void
    {
        $answer = static fn (CheckRequest $request): ?CheckReply => $request->point === CheckPoint::AfterReading
            ? CheckReply::stop(StopKind::User)
            : null;

        $calls = FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1')]);
        $outcome = $this->loop([$calls], answer: $answer);

        $this->assertSame([LoopEndingKind::Stopped, LoopStop::User], [$outcome->ending->kind, $outcome->ending->stop]);
        $this->assertSame(['model_text', 'response_end', 'tool_result', 'interruption'], $this->appended());
        $this->assertSame([], $this->tools->ran);
    }

    /**
     * 読み終えた後の費用の上限(9.11 の S33)は、道具を動かさず、結果も中断の印も付けずに止める(9.5 の表。理由を添える)。止める印の
     * 確かめ(S12)と違い、確定したモデルの文と応答の終わりだけが残る。
     */
    public function test_a_cost_stop_after_reading_runs_no_tools_and_writes_no_results(): void
    {
        $answer = static fn (CheckRequest $request): ?CheckReply => $request->point === CheckPoint::AfterReading
            ? CheckReply::stop(StopKind::CostLimit, 'day')
            : null;

        $calls = FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1')]);
        $ending = $this->loop([$calls], answer: $answer)->ending;

        $expected = [LoopEndingKind::Stopped, LoopStop::CostLimit, 'day'];
        $this->assertSame($expected, [$ending->kind, $ending->stop, $ending->stopReason]);
        $this->assertSame(['model_text', 'response_end'], $this->appended());
        $this->assertSame([], $this->tools->ran);
    }

    public function test_a_gone_conversation_stops_as_the_user_without_more_writes(): void
    {
        $reply = static fn (CoreNotice $notice): ?NoticeReply => $notice instanceof AppendNotice
            ? NoticeReply::noConversation()
            : null;

        $outcome = $this->loop([FakeStepGateway::response('答え')], reply: $reply);

        $this->assertSame([LoopEndingKind::Stopped, LoopStop::User], [$outcome->ending->kind, $outcome->ending->stop]);
        $this->assertCount(1, $this->receivers->noticesOf(AppendNotice::class));
    }

    /** 同じ応答の中で前の呼び出しと同じ id の呼び出しは動かさない(前は動く = 13.3) */
    public function test_a_repeated_call_id_in_one_response_runs_only_the_first(): void
    {
        $this->loop([
            FakeStepGateway::response('', FinishReason::ToolCalls, [
                self::call('c1'),
                self::call('c1'),
                self::call('c2'),
            ]),
            FakeStepGateway::response('答え'),
        ]);

        $this->assertSame(['c1', 'c2'], $this->tools->ran);
    }

    public function test_a_refusal_fails_as_refused(): void
    {
        $outcome = $this->loop([FakeStepGateway::response('', FinishReason::ContentFilter)]);

        $ending = $outcome->ending;
        $this->assertSame([LoopEndingKind::Failed, LoopFailure::Refused], [$ending->kind, $ending->failure]);
    }

    public function test_an_unknown_finish_of_a_non_streamed_answer_fails_as_the_provider(): void
    {
        $outcome = $this->loop([FakeStepGateway::response('答え', FinishReason::Error)]);

        $ending = $outcome->ending;
        $this->assertSame([LoopEndingKind::Failed, LoopFailure::Provider], [$ending->kind, $ending->failure]);
    }

    /** 起きない組(道具を呼ぶ前に費用の上限)を受けたら作りの誤り(13.5) */
    public function test_an_impossible_check_reply_is_a_construction_error(): void
    {
        $answer = static fn (CheckRequest $request): ?CheckReply => $request->point === CheckPoint::BeforeTool
            ? CheckReply::stop(StopKind::CostLimit)
            : null;

        $this->expectException(LogicException::class);

        $this->loop([FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1')])], answer: $answer);
    }

    /**
     * 保存した文(docs の 設計/AI/会話の中核.md 13.2): 道具の結果と中断の印は、組んだ時の一覧の印が今の印と同じなら組んだ文のまま送り、
     * 違えば保存した値と本文から今の版で組み直す(偽の文の口の一覧 = <mark>)。
     *
     * @return array<string, array{list<string>, string, string}>
     */
    public static function storedTexts(): array
    {
        return [
            '同じ一覧' => [['<mark>'], '組んだ結果', '組んだ中断'],
            '違う一覧' => [['<old>'], 'b< mark>', '[interrupted]'],
        ];
    }

    /**
     * @param  list<string>  $forms
     */
    #[DataProvider('storedTexts')]
    public function test_stored_texts_are_sent_while_the_forms_are_the_same(
        array $forms,
        string $result,
        string $mark,
    ): void {
        $id = StoredTexts::formsId($forms);
        $before = [
            ConversationItem::modelText('r0', [ConversationItem::toolCallBlock('c0', 'search', [])]),
            ConversationItem::toolResult('c0', null, [], 'b<mark>', false, ['text' => '組んだ結果', 'forms_id' => $id]),
            ConversationItem::interruption(false, ['text' => '組んだ中断', 'forms_id' => $id]),
        ];

        $this->loop([FakeStepGateway::response('答え')], before: $before);

        $messages = $this->receivers->noticesOf(ExchangeNotice::class)[0]->request['messages'];
        $texts = array_map(
            static fn (array $block): string => (string) ($block['text'] ?? ''),
            array_merge(...array_column($messages, 'blocks')),
        );
        $this->assertContains($result, $texts);
        $this->assertContains($mark, $texts);
    }
}
