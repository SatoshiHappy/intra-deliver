<?php

namespace Tests\Unit\Actions\Ai\Core\Contract;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\BeforeSendNotice;
use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\NoticeReply;
use App\Actions\Ai\Core\ResponseFinish;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\SendNotSent;
use App\Actions\Ai\Core\SentResponse;
use App\Actions\Ai\Core\StopKind;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use LogicException;
use Tests\Unit\Actions\Ai\Core\Support\FakeStepGateway;

/**
 * 1 回の送信の約束(題 = docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.10、口 = 設計/AI/会話の中核の口と受け手.md 13.3):
 * 送る前だけ確かめ、確定したモデルの文の項目・終わりの理由・使用量・構造化の出力を返す。送る量の上限は、その送信で送る量。
 */
abstract class SendContractTestCase extends CoreContractTestCase
{
    public function test_a_title_send_returns_the_items_and_checks_only_before_sending(): void
    {
        $structured = ['title' => '会議の検索'];
        $usage = new TextUsage(10, 5);
        $response = new StepResponse('{"title":"会議の検索"}', [], FinishReason::Stop, $usage, new Meta, $structured);

        $result = $this->send($response);

        $this->assertInstanceOf(SentResponse::class, $result);
        $this->assertSame([ResponseFinish::Stop, $structured], [$result->finish, $result->structured]);
        $this->assertSame([[ConversationItem::textBlock('{"title":"会議の検索"}')]], array_map(
            static fn (ConversationItem $item): array => $item->content['blocks'],
            $result->items,
        ));
        $points = array_map(static fn (CheckRequest $request): CheckPoint => $request->point, $this->receivers->checks);
        $this->assertSame([CheckPoint::BeforeSend], $points);
        $this->assertSame(SendKind::Title, $this->receivers->checks[0]->kind);
        $before = $this->receivers->noticesOf(BeforeSendNotice::class)[0];
        $this->assertSame($before->estimate, $this->receivers->checks[0]->sendLimit);
        $this->assertSame([false], array_column($this->gateway->sent, 'stream'));
    }

    public function test_a_cost_stop_before_sending_is_not_sent(): void
    {
        $answer = static fn (CheckRequest $request): CheckReply => CheckReply::stop(StopKind::CostLimit, '上限');

        $result = $this->send(FakeStepGateway::response('題'), $answer);

        $this->assertEquals(new SendNotSent(StopKind::CostLimit, '上限'), $result);
        $this->assertSame([], $this->gateway->sent);
    }

    public function test_a_gone_conversation_on_a_title_notice_is_a_construction_error(): void
    {
        $this->expectException(LogicException::class);

        $this->send(FakeStepGateway::response('題'), reply: static fn (): NoticeReply => NoticeReply::noConversation());
    }

    public function test_a_user_stop_before_a_title_send_is_a_construction_error(): void
    {
        $this->expectException(LogicException::class);

        $this->send(FakeStepGateway::response('題'), static fn (): CheckReply => CheckReply::stop(StopKind::User));
    }
}
