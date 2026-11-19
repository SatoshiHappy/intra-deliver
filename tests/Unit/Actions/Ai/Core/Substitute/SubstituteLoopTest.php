<?php

namespace Tests\Unit\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\BeforeSendNotice;
use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\LoopEndingKind;
use App\Actions\Ai\Core\LoopFailure;
use App\Actions\Ai\Core\LoopStop;
use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\StopKind;
use App\Actions\Ai\Core\Substitute\SubstituteConversationCore;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use RuntimeException;
use Tests\Unit\Actions\Ai\Core\Contract\CoreContractTestCase;
use Tests\Unit\Actions\Ai\Core\Support\FakeStepGateway;

/**
 * 代わりの版だけの回の答えの振る舞い(docs の 設計/AI/エージェントの実行/README.md 9.1 の「代わりの版がしないこと」・
 * 設計/AI/エージェントの実行/状態の移り方.md 9.11 の S20・S28): 続きを書かせず、長すぎると断られたらすぐ止め、流れの途中の失敗は送り直さない。
 */
final class SubstituteLoopTest extends CoreContractTestCase
{
    protected function core(): ConversationCore
    {
        return new SubstituteConversationCore;
    }

    public function test_an_answer_cut_by_the_output_limit_fails_at_once(): void
    {
        $outcome = $this->loop([FakeStepGateway::response('途中', FinishReason::Length)]);

        $ending = $outcome->ending;
        $expected = [LoopEndingKind::Failed, LoopFailure::ContinuationsExhausted];
        $this->assertSame($expected, [$ending->kind, $ending->failure]);
        $this->assertCount(1, $this->gateway->sent);
    }

    public function test_a_too_long_refusal_stops_by_length_without_sending_again(): void
    {
        $outcome = $this->loop([self::tooLong()], traits: self::traits());

        $ending = $outcome->ending;
        $this->assertSame([LoopEndingKind::Stopped, LoopStop::Length], [$ending->kind, $ending->stop]);
        $this->assertCount(1, $this->gateway->sent);
    }

    /** 流れの途中の失敗: 送り直さず失敗(接続先)。確定した呼び出しは取り消しの印を書かずに残る */
    public function test_a_failure_in_the_middle_of_a_stream_fails_without_cancelling(): void
    {
        $events = [new ToolCallEvent('e1', self::call('c1'), 0), new RuntimeException('切れた')];

        $outcome = $this->loop([['events' => $events, 'return' => null]], stream: true);

        $ending = $outcome->ending;
        $this->assertSame([LoopEndingKind::Failed, LoopFailure::Provider], [$ending->kind, $ending->failure]);
        $this->assertSame(['model_text'], $this->appended());
        $this->assertSame([], $this->tools->ran);
    }

    public function test_a_time_limit_before_a_tool_writes_the_limit_results_only(): void
    {
        $answer = static fn (CheckRequest $request): ?CheckReply => $request->point === CheckPoint::BeforeTool
            ? CheckReply::stop(StopKind::TimeLimit)
            : null;

        $outcome = $this->loop(
            [FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1'), self::call('c2')])],
            answer: $answer,
        );

        $ending = $outcome->ending;
        $this->assertSame([LoopEndingKind::Stopped, LoopStop::TimeLimit], [$ending->kind, $ending->stop]);
        $this->assertSame(['model_text', 'response_end', 'tool_result', 'tool_result'], $this->appended());
    }

    public function test_a_user_stop_before_a_tool_cancels_the_rest_and_writes_the_interruption(): void
    {
        $answer = static fn (CheckRequest $request): ?CheckReply => $request->point === CheckPoint::BeforeTool
            ? CheckReply::stop(StopKind::User)
            : null;

        $outcome = $this->loop(
            [FakeStepGateway::response('', FinishReason::ToolCalls, [self::call('c1'), self::call('c2')])],
            answer: $answer,
        );

        $this->assertSame([LoopEndingKind::Stopped, LoopStop::User], [$outcome->ending->kind, $outcome->ending->stop]);
        $expected = ['model_text', 'response_end', 'tool_result', 'tool_result', 'interruption'];
        $this->assertSame($expected, $this->appended());
    }

    public function test_a_user_stop_before_sending_writes_the_interruption(): void
    {
        $answer = static fn (CheckRequest $request): ?CheckReply => $request->point === CheckPoint::BeforeSend
            ? CheckReply::stop(StopKind::User)
            : null;

        $outcome = $this->loop([], answer: $answer);

        $this->assertSame([LoopEndingKind::Stopped, LoopStop::User], [$outcome->ending->kind, $outcome->ending->stop]);
        $this->assertSame(['interruption'], $this->appended());
    }

    public function test_a_cost_stop_before_sending_writes_nothing(): void
    {
        $answer = static fn (CheckRequest $request): ?CheckReply => $request->point === CheckPoint::BeforeSend
            ? CheckReply::stop(StopKind::CostLimit, '上限')
            : null;

        $outcome = $this->loop([], answer: $answer);

        $ending = $outcome->ending;
        $expected = [LoopEndingKind::Stopped, LoopStop::CostLimit, '上限'];
        $this->assertSame($expected, [$ending->kind, $ending->stop, $ending->stopReason]);
        $this->assertSame([], $this->appended());
    }

    /** 送る前の知らせは 1 回の送信につき 1 つ(試しは 1 回目だけ) */
    public function test_each_send_is_a_single_attempt(): void
    {
        $this->loop([FakeStepGateway::response('答え')]);

        $before = $this->receivers->noticesOf(BeforeSendNotice::class);
        $this->assertSame([1, null], [$before[0]->attempt, $before[0]->retryOfStepId]);
    }

    private static function traits(): ProviderTraits
    {
        $pattern = '/prompt is too long: (?<actual>\d+) tokens > (?<limit>\d+) maximum/';

        return new ProviderTraits(tooLongPatterns: [$pattern]);
    }

    private static function tooLong(): RequestException
    {
        $body = 'prompt is too long: 30000 tokens > 29000 maximum';

        return new RequestException(new Response(new Psr7Response(400, [], $body)));
    }
}
