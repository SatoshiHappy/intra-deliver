<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\LoopEnding;
use App\Actions\Ai\Core\LoopFailure;
use App\Actions\Ai\Core\LoopRequest;
use App\Actions\Ai\Core\LoopStop;
use App\Actions\Ai\Core\ResponseFinish;
use App\Actions\Ai\Core\SendFailure;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\StopKind;
use LogicException;

/**
 * 回の答えの送信とその結び(終わりの理由の表の正本 = docs の 設計/AI/会話の中核.md 13.6、続きを書かせないこと =
 * 設計/AI/エージェントの実行/README.md 9.1 の「代わりの版がしないこと」)。
 */
final readonly class SubstituteTurn
{
    public function __construct(
        private LoopRequest $request,
        private SubstituteState $state,
        private SubstituteStops $stops,
        private SubstituteSender $sender,
        private SubstituteTools $tools,
    ) {}

    /** 送って結ぶ(終われば終わり方、次の回へ進むなら null) */
    public function sent(SubstitutePlan $plan): ?LoopEnding
    {
        $receivers = $this->request->receivers;
        $sink = new TurnAnswerSink($this->state, $receivers->notifier, $receivers->clock, $this->request->target);
        $this->state->kind = SendKind::Turn;
        $this->state->thinking->sending();
        $outcome = $this->sender->send($plan, $sink);

        if ($outcome->stop !== null) {
            return $this->stopped($outcome, $sink);
        }

        if ($outcome->failure !== null) {
            return self::failed($outcome->failure);
        }

        $reply = SubstituteChecks::at($receivers->checker, CheckPoint::AfterReading, SendKind::Turn);

        if ($reply->stop !== null) {
            if ($reply->stop === StopKind::User) {
                $this->stops->whileReading($sink->callIds());
            }

            return LoopEnding::stopped(LoopStop::fromStopKind($reply->stop), $reply->reason);
        }

        $response = $outcome->response ?? throw new LogicException('応答の結びに応答が無い');

        return $this->finished(SubstituteUsage::finish($response->finishReason), $sink);
    }

    private function stopped(SubstituteOutcome $outcome, TurnAnswerSink $sink): LoopEnding
    {
        $stop = $outcome->stop ?? throw new LogicException('止めた結びに区分が無い');

        if ($outcome->sent) {
            $this->stops->whileReading($sink->callIds());
        } else {
            $this->stops->beforeSend($stop);
        }

        return LoopEnding::stopped(LoopStop::fromStopKind($stop), $outcome->stopReason);
    }

    private static function failed(SendFailure $failure): LoopEnding
    {
        return match ($failure) {
            SendFailure::TooLong => LoopEnding::stopped(LoopStop::Length),
            SendFailure::Timeout => LoopEnding::failed(LoopFailure::Timeout),
            SendFailure::Provider => LoopEnding::failed(LoopFailure::Provider),
        };
    }

    private function finished(ResponseFinish $finish, TurnAnswerSink $sink): ?LoopEnding
    {
        $answers = in_array($finish, [ResponseFinish::Stop, ResponseFinish::ToolCalls, ResponseFinish::Length], true);

        if ($finish === ResponseFinish::ContentFilter) {
            return LoopEnding::failed(LoopFailure::Refused);
        }

        if (! $answers) {
            return LoopEnding::failed(LoopFailure::Provider);
        }

        if ($sink->hadCalls()) {
            return $this->tools->run($sink->calls, false, $this->request->maxTurns);
        }

        return $finish === ResponseFinish::Length
            ? LoopEnding::failed(LoopFailure::ContinuationsExhausted)
            : LoopEnding::succeeded();
    }
}
