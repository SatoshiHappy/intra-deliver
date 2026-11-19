<?php

namespace Tests\Unit\Actions\Ai\Core\Contract;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\AddedTextValues;
use App\Actions\Ai\Core\AppendNotice;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\CoreNotice;
use App\Actions\Ai\Core\CoreToolCall;
use App\Actions\Ai\Core\LoopOutcome;
use App\Actions\Ai\Core\LoopReceivers;
use App\Actions\Ai\Core\LoopRequest;
use App\Actions\Ai\Core\NoticeReply;
use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\SendReceivers;
use App\Actions\Ai\Core\SendRequest;
use App\Actions\Ai\Core\SendResult;
use Closure;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Unit\Actions\Ai\Core\Support\CoreTestCase;
use Tests\Unit\Actions\Ai\Core\Support\FakeClock;
use Tests\Unit\Actions\Ai\Core\Support\FakeLoopTexts;
use Tests\Unit\Actions\Ai\Core\Support\FakeReceivers;
use Tests\Unit\Actions\Ai\Core\Support\FakeStepGateway;
use Tests\Unit\Actions\Ai\Core\Support\FakeTools;

/**
 * 会話の中核の約束のテストの基底(docs の 設計/AI/検査で守る結果.md の中核と繋ぎの項: 中核の約束は偽のゲートウェイと偽の受け手で
 * 両方の版に流す)。版ごとのテストが中核を渡して継ぐ。
 */
abstract class CoreContractTestCase extends CoreTestCase
{
    protected FakeReceivers $receivers;

    protected FakeTools $tools;

    protected FakeStepGateway $gateway;

    /** 試す中核(版ごと) */
    abstract protected function core(): ConversationCore;

    /**
     * @param  list<mixed>  $scripts
     * @param  Closure(CheckRequest): (CheckReply|null)|null  $answer
     * @param  Closure(CoreNotice): (NoticeReply|null)|null  $reply
     * @param  list<ConversationItem>  $before  問いの後ろに置く履歴
     * @param  list<CoreToolCall>  $preRun  先に動かす呼び出し
     */
    protected function loop(
        array $scripts,
        int $maxTurns = 5,
        ?Closure $answer = null,
        ?Closure $reply = null,
        string $question = 'q',
        int $window = 200000,
        array $before = [],
        ?ProviderTraits $traits = null,
        bool $stream = false,
        array $preRun = [],
        ?AddedTextValues $added = null,
    ): LoopOutcome {
        $this->receivers = new FakeReceivers($answer, $reply);
        $this->tools = new FakeTools;
        $fake = $this->receivers;
        $receivers = new LoopReceivers($this->tools, $fake, $fake, new FakeClock, new FakeLoopTexts, $this->tools);
        $this->gateway = new FakeStepGateway($scripts);
        $request = new LoopRequest(
            $this->target($this->gateway, $traits, window: $window, maxOutput: 1000),
            [ConversationItem::userText($question), ...$before],
            [],
            $stream,
            [],
            $maxTurns,
            1,
            $preRun,
            $added ?? new AddedTextValues('2026-11-18'),
            $receivers,
        );

        return $this->core()->loop($request);
    }

    /**
     * 題の 1 回の送信。
     *
     * @param  Closure(CheckRequest): (CheckReply|null)|null  $answer
     * @param  Closure(CoreNotice): (NoticeReply|null)|null  $reply
     */
    protected function send(StepResponse $response, ?Closure $answer = null, ?Closure $reply = null): SendResult
    {
        $this->receivers = new FakeReceivers($answer, $reply);
        $this->gateway = new FakeStepGateway([$response]);
        $request = new SendRequest(
            $this->target($this->gateway),
            '題を付ける',
            [ConversationItem::userText('会議を探して')],
            [],
            null,
            [],
            new SendReceivers($this->receivers, $this->receivers, new FakeClock, new FakeLoopTexts),
        );

        return $this->core()->send($request);
    }

    /**
     * 会話の保存に足した項目の種類(知らせの順)。
     *
     * @return list<string>
     */
    protected function appended(): array
    {
        $types = [];

        foreach ($this->receivers->noticesOf(AppendNotice::class) as $notice) {
            foreach ($notice->items as $item) {
                $types[] = $item->type->value;
            }
        }

        return $types;
    }

    protected static function call(string $id, string $name = 'search'): ToolCall
    {
        return new ToolCall($id, $name, []);
    }
}
