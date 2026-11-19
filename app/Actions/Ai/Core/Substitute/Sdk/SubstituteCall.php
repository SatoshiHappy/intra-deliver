<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use App\Actions\Ai\Core\CoreClock;
use App\Actions\Ai\Core\SendFailure;
use Generator;
use GuzzleHttp\Psr7\Exception\TimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Streaming\Events\Error;
use RuntimeException;

/**
 * 代わりの版の 1 回の試し(送り直さない = docs の 設計/AI/エージェントの実行/README.md 9.1 の「代わりの版がしないこと」。読む途中の確かめの
 * 位置 = 設計/AI/会話の中核.md 13.5、流れの終わりの扱い = 同じ文書の 13.6)。
 */
final readonly class SubstituteCall
{
    public function __construct(
        private CoreClock $clock,
        private int $progressSeconds,
        private SubstituteErrors $errors,
    ) {}

    public function attempt(SubstituteInput $input, SubstituteReader $reader): SubstituteAttempt
    {
        $reading = new SubstituteReading($reader);
        $result = $input->stream ? $this->streamed($input, $reading) : $this->generated($input);

        if ($result instanceof StepResponse) {
            $reading->finish($result->text, $result->toolCalls, $input->stream);

            return SubstituteAttempt::responded($reading, $result);
        }

        return $result === null ? SubstituteAttempt::stopped($reading) : SubstituteAttempt::failed($reading, $result);
    }

    private function generated(SubstituteInput $input): StepResponse|SubstituteFailure
    {
        try {
            return $input->gateway->generateTextStep(
                $input->provider,
                $input->model,
                $input->instructions,
                $input->messages,
                $input->tools,
                $input->schema,
                $input->options,
                $input->timeoutSeconds,
                new StepContext,
            );
        } catch (RequestException|ConnectionException|AiException|TimeoutException $exception) {
            return $this->errors->fromException($exception, false);
        }
    }

    /** 流れを読む(返す物 = 応答・失敗・読む途中で止めた(null)) */
    private function streamed(SubstituteInput $input, SubstituteReading $reading): StepResponse|SubstituteFailure|null
    {
        try {
            $events = $this->events($input);
            $valid = $events->valid();
        } catch (RequestException|ConnectionException|AiException|TimeoutException $exception) {
            return $this->errors->fromException($exception, false);
        }

        $lastCheck = $this->clock->now()->getTimestamp();

        while ($valid) {
            $event = $events->current();

            if ($event instanceof Error) {
                return $this->errors->fromStreamError($event);
            }

            $reading->take($event);

            if ($this->stopsNow($lastCheck, $reading)) {
                return null;
            }

            $valid = $this->advanced($events);

            if ($valid instanceof SubstituteFailure) {
                return $valid;
            }
        }

        $response = $events->getReturn();

        return $response === null || $response->finishReason === FinishReason::Unknown
            ? new SubstituteFailure(SendFailure::Provider, null, true)
            : $response;
    }

    /** 途中の文を書く間隔ごとの読む途中の確かめ(確かめたら最後に確かめた時刻を進める) */
    private function stopsNow(int &$lastCheck, SubstituteReading $reading): bool
    {
        $now = $this->clock->now()->getTimestamp();

        if ($now - $lastCheck < $this->progressSeconds) {
            return false;
        }

        $lastCheck = $now;

        return $reading->reader->stopsWhileReading();
    }

    /**
     * @param  Generator<int, mixed, mixed, StepResponse|null>  $events
     */
    private function advanced(Generator $events): bool|SubstituteFailure
    {
        try {
            $events->next();

            return $events->valid();
        } catch (RequestException|ConnectionException|AiException|RuntimeException $exception) {
            return $this->errors->fromException($exception, true);
        }
    }

    /**
     * @return Generator<int, mixed, mixed, StepResponse|null>
     */
    private function events(SubstituteInput $input): Generator
    {
        return $input->gateway->generateStreamStep(
            (string) Str::uuid(),
            $input->provider,
            $input->model,
            $input->instructions,
            $input->messages,
            $input->tools,
            $input->schema,
            $input->options,
            $input->timeoutSeconds,
            new StepContext,
        );
    }
}
