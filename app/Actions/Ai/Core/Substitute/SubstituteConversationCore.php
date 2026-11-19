<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\CompactThresholds;
use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\LoopOutcome;
use App\Actions\Ai\Core\LoopRequest;
use App\Actions\Ai\Core\ModelSpec;
use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\SendFailed;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\SendNotSent;
use App\Actions\Ai\Core\SendReceivers;
use App\Actions\Ai\Core\SendRequest;
use App\Actions\Ai\Core\SendResult;
use App\Actions\Ai\Core\SentResponse;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteCall;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteErrors;
use LogicException;

/**
 * 代わりの版の会話の中核(公開の版 = docs の 設計/AI/会話の中核.md 13.1。しないことの正本 =
 * 設計/AI/エージェントの実行/README.md 9.1 の「代わりの版がしないこと」)。
 */
final class SubstituteConversationCore implements ConversationCore
{
    /** 1 回の送信の読む途中の確かめの刻み(題は流さないので効かない) */
    private const SINGLE_PROGRESS_SECONDS = 1;

    public function send(SendRequest $request): SendResult
    {
        $given = $request->receivers;
        $notifier = new CheckedNotices($given->notifier);
        $receivers = new SendReceivers($given->checker, $notifier, $given->clock, $given->texts);
        $messages = (new SentHistory($receivers->texts, $request->target))->build($request->history);
        $plan = SubstitutePlans::of(new SubstituteParts(
            $request->target,
            SendKind::Title,
            $request->instructions,
            $messages,
            $request->tools,
            $request->schema,
            false,
            $request->providerOptions,
            false,
            $request->target->model->maxOutputTokens,
            null,
            SubstitutePlans::toolChars($request->tools),
        ));
        $sink = new SingleSendSink;
        $sender = self::sender($receivers, $request->target->traits, self::SINGLE_PROGRESS_SECONDS);
        $outcome = $sender->send($plan, $sink);

        return match (true) {
            $outcome->response !== null => new SentResponse(
                $sink->items,
                SubstituteUsage::finish($outcome->response->finishReason),
                SubstituteUsage::of($outcome->response->usage),
                $outcome->response->structured,
            ),
            $outcome->failure !== null => new SendFailed(
                $outcome->failure,
                $outcome->actualTokens,
                $outcome->limitTokens,
            ),
            default => new SendNotSent($outcome->stop ?? throw new LogicException('結びに止める区分が無い'), $outcome->stopReason),
        };
    }

    public function loop(LoopRequest $request): LoopOutcome
    {
        return (new SubstituteLoop($request))->run();
    }

    public function thresholds(ModelSpec $model): CompactThresholds
    {
        return SubstituteAmounts::of($model);
    }

    /** 送る部品(1 回の送信とループで同じ組み方) */
    public static function sender(
        SendReceivers $receivers,
        ProviderTraits $traits,
        int $progressSeconds,
    ): SubstituteSender {
        return new SubstituteSender(
            $receivers,
            new SubstituteCall($receivers->clock, $progressSeconds, new SubstituteErrors($traits)),
        );
    }
}
