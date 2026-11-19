<?php

namespace Tests\Unit\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\AppendNotice;
use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\CoreNotice;
use App\Actions\Ai\Core\LoopEndingKind;
use App\Actions\Ai\Core\LoopFailure;
use App\Actions\Ai\Core\LoopStop;
use App\Actions\Ai\Core\NoticeReply;
use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\StopKind;
use App\Actions\Ai\Core\Substitute\SubstituteConversationCore;
use JsonException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\ReasoningStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Tests\Unit\Actions\Ai\Core\Contract\CoreContractTestCase;
use Tests\Unit\Actions\Ai\Core\Support\FakeStepGateway;

/**
 * 代わりの版の応答の読みと誤りの読み(確定の時 = docs の 設計/AI/会話の中核の口と受け手.md 13.3、流れる送信の unknown =
 * 設計/AI/会話の中核.md 13.6、誤りの読み方 = 設計/AI/会話の中核.md 13.1)と、道具の段の重なり・止め方(設計/AI/会話の中核の口と受け手.md 13.3・
 * 設計/AI/エージェントの実行/道具の包みと止める.md 9.5)。
 */
final class SubstituteReadingTest extends CoreContractTestCase
{
    protected function core(): ConversationCore
    {
        return new SubstituteConversationCore;
    }

    /** 流れる送信の unknown は流れの途中の失敗(残りの文を確定せず、応答の終わりを書かない) */
    public function test_an_unknown_finish_of_a_streamed_answer_is_a_failure_in_the_middle(): void
    {
        $events = [new TextDelta('e1', 'm', '途中', 0)];
        $return = FakeStepGateway::response('途中', FinishReason::Unknown);

        $outcome = $this->loop([['events' => $events, 'return' => $return]], stream: true);

        $ending = $outcome->ending;
        $this->assertSame([LoopEndingKind::Failed, LoopFailure::Provider], [$ending->kind, $ending->failure]);
        $this->assertSame([], $this->appended());
    }

    /** 思考の始まりで、それまでの文を確定する */
    public function test_the_text_before_a_reasoning_start_is_confirmed(): void
    {
        $events = [
            new TextDelta('e1', 'm', '前', 0),
            new ReasoningStart('e2', 'r', 0),
            new TextDelta('e3', 'm', '後', 0),
        ];

        $this->loop([['events' => $events, 'return' => FakeStepGateway::response('前後')]], stream: true);

        $this->assertSame(['model_text', 'model_text', 'response_end'], $this->appended());
    }

    /** 流れの誤りの事象が長すぎるの句に当たれば止めた(長さ) */
    public function test_a_too_long_error_event_stops_by_length(): void
    {
        $events = [new Error('e1', 'invalid_request_error', 'prompt is too long', false, 0)];
        $traits = new ProviderTraits(tooLongPatterns: ['/prompt is too long/']);

        $outcome = $this->loop([['events' => $events, 'return' => null]], traits: $traits, stream: true);

        $ending = $outcome->ending;
        $this->assertSame([LoopEndingKind::Stopped, LoopStop::Length], [$ending->kind, $ending->stop]);
    }

    /** 前の例外が HTTP の例外でない SDK の例外は、句に当たらなければ失敗(接続先) */
    public function test_a_wrapped_sdk_error_fails_as_the_provider(): void
    {
        $outcome = $this->loop([new AiException('応答が読めない', 0, new JsonException('bad'))]);

        $ending = $outcome->ending;
        $this->assertSame([LoopEndingKind::Failed, LoopFailure::Provider], [$ending->kind, $ending->failure]);
    }

    /** 呼び出しがどれも重なりだった応答も、道具の段を通って次の回へ進む */
    public function test_a_response_whose_calls_all_overlap_goes_on_to_the_next_turn(): void
    {
        $reply = static fn (CoreNotice $notice): ?NoticeReply => $notice instanceof AppendNotice
            && $notice->items[0]->type->value === 'model_text'
            && ($notice->items[0]->content['blocks'][0]['type'] ?? null) === 'tool_call'
            ? NoticeReply::duplicateCalls(['c1'])
            : null;

        $outcome = $this->loop([
            FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1')]),
            FakeStepGateway::response('答え'),
        ], reply: $reply);

        $this->assertSame(LoopEndingKind::Succeeded, $outcome->ending->kind);
        $this->assertSame([], $this->tools->ran);
        $this->assertCount(2, $this->gateway->sent);
    }

    /** 道具を呼ぶ前に止めたら、同じステップで先に問うと判定した呼び出しにも結果を付ける */
    public function test_a_stop_before_a_tool_also_answers_the_asking_calls(): void
    {
        $seen = 0;
        $answer = static function (CheckRequest $request) use (&$seen): ?CheckReply {
            if ($request->point !== CheckPoint::BeforeTool) {
                return null;
            }

            return ++$seen === 2 ? CheckReply::stop(StopKind::User) : null;
        };

        $this->loop(
            [FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('a1', 'ask'), self::call('c2')])],
            answer: $answer,
        );

        $items = array_merge(...array_map(
            static fn (AppendNotice $notice): array => $notice->items,
            $this->receivers->noticesOf(AppendNotice::class),
        ));
        $results = array_filter($items, static fn ($item): bool => $item->type->value === 'tool_result');

        $this->assertSame(['a1', 'c2'], array_values(array_map(
            static fn ($item): mixed => $item->content['call_id'],
            $results,
        )));
    }
}
