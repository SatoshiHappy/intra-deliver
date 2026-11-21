<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\AfterSendNotice;
use App\Actions\Ai\Core\BeforeSendNotice;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\ExchangeNotice;
use App\Actions\Ai\Core\SendReceivers;
use App\Actions\Ai\Core\StepFailedNotice;
use App\Actions\Ai\Core\StepStoppedNotice;
use App\Actions\Ai\Core\StopKind;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteAttempt;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteCall;
use Illuminate\Support\Str;
use LogicException;

/**
 * 代わりの版の 1 回の送信(送信の順の正本 = docs の 設計/AI/会話の中核.md 13.5、知らせ = 設計/AI/会話の中核の口と受け手.md 13.3)。
 * 代わりの版は送り直さないので、試しは 1 回だけ。
 */
final readonly class SubstituteSender
{
    public function __construct(private SendReceivers $receivers, private SubstituteCall $call) {}

    public function send(SubstitutePlan $plan, SubstituteSink $sink): SubstituteOutcome
    {
        $reply = SubstituteChecks::ask($this->receivers->checker, CheckRequest::beforeSend(
            $plan->kind,
            $plan->chars,
            $plan->input->options->maxTokens ?? 0,
            $plan->sendLimit,
        ));

        if ($reply->stop !== null) {
            return SubstituteOutcome::notSent($reply->stop, $reply->reason);
        }

        $stepId = $reply->stepId ?? throw new LogicException('送る前の位置の続けるにステップの番号が無い');
        $responseId = (string) Str::uuid();
        $notifier = $this->receivers->notifier;
        $notifier->notify(new BeforeSendNotice($stepId, $plan->kind, 1, null, $plan->estimate));
        $listener = new SubstituteListener($this->receivers->checker, $plan->kind, $sink, $responseId, $stepId);
        $attempt = $this->call->attempt($plan->input, $listener);
        $notifier->notify(new ExchangeNotice($stepId, $plan->record, $attempt->response?->toArray()));

        return $this->closed($attempt, $stepId, $responseId, $sink, $listener->stopReason);
    }

    private function closed(
        SubstituteAttempt $attempt,
        int $stepId,
        string $responseId,
        SubstituteSink $sink,
        ?string $stopReason,
    ): SubstituteOutcome {
        $notifier = $this->receivers->notifier;

        if ($attempt->response !== null) {
            $sink->responded($responseId, $attempt->response, $attempt->reading);
            $usage = SubstituteUsage::of($attempt->response->usage);
            $finish = SubstituteUsage::finish($attempt->response->finishReason);
            $notifier->notify(new AfterSendNotice($stepId, $usage, $finish, null, false));

            return SubstituteOutcome::responded($attempt->response, $attempt->reading, $stepId);
        }

        if ($attempt->failure === null) {
            $notifier->notify(new StepStoppedNotice($stepId, StopKind::User));

            return SubstituteOutcome::stopped($stopReason, $attempt->reading);
        }

        $failure = $attempt->failure;
        $notifier->notify(new AfterSendNotice($stepId, null, null, $failure->status, $failure->midStream));
        $notifier->notify(new StepFailedNotice($stepId, $failure->failure));

        return SubstituteOutcome::failed(
            $failure->failure,
            $failure->actualTokens,
            $failure->limitTokens,
            $attempt->reading,
        );
    }
}
