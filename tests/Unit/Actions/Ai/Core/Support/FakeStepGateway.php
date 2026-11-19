<?php

namespace Tests\Unit\Actions\Ai\Core\Support;

use Generator;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Streaming\Events\StreamEvent;
use LogicException;
use Throwable;

/**
 * 中核のテストの 1 ステップの口(試しごとの台本を順に返す)。台本 = 応答・投げる例外・流れの事象の列(列の中の例外は、そこで投げる)と
 * 流れの終わりの応答。送った試しの出力の上限と流すかを控える。
 *
 * @phpstan-type Streamed array{events: list<StreamEvent|Throwable>, return: StepResponse|null}
 */
final class FakeStepGateway implements StepTextGateway
{
    /** @var list<array{stream: bool, maxTokens: int|null}> */
    public array $sent = [];

    /**
     * @param  list<StepResponse|Throwable|Streamed>  $scripts
     */
    public function __construct(private array $scripts) {}

    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public static function response(
        string $text,
        FinishReason $finish = FinishReason::Stop,
        array $toolCalls = [],
    ): StepResponse {
        return new StepResponse($text, $toolCalls, $finish, new TextUsage(10, 5), new Meta('fake', 'm'));
    }

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        $script = $this->next(false, $options);

        if ($script instanceof Throwable) {
            throw $script;
        }

        if (! $script instanceof StepResponse) {
            throw new LogicException('流さない試しに流れの台本を当てた');
        }

        return $script;
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $script = $this->next(true, $options);

        if ($script instanceof Throwable) {
            throw $script;
        }

        $events = $script instanceof StepResponse ? ['events' => [], 'return' => $script] : $script;

        foreach ($events['events'] as $event) {
            if ($event instanceof Throwable) {
                throw $event;
            }

            yield $event;
        }

        return $events['return'];
    }

    /**
     * @return StepResponse|Throwable|Streamed
     */
    private function next(bool $stream, ?TextGenerationOptions $options): StepResponse|Throwable|array
    {
        $this->sent[] = ['stream' => $stream, 'maxTokens' => $options?->maxTokens];

        return array_shift($this->scripts) ?? throw new LogicException('台本が尽きた');
    }
}
