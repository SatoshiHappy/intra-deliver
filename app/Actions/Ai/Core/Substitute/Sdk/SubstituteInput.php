<?php

namespace App\Actions\Ai\Core\Substitute\Sdk;

use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;

/**
 * 1 ステップの口に渡す物(docs の 設計/AI/会話の中核.md 13.1。継続の印を渡さず、最後のステップの印を立てない)。
 */
final readonly class SubstituteInput
{
    /**
     * @param  list<Message>  $messages
     * @param  list<Tool>  $tools
     * @param  array<string, Type>|null  $schema
     */
    public function __construct(
        public StepTextGateway $gateway,
        public TextProvider $provider,
        public string $model,
        public string $instructions,
        public array $messages,
        public array $tools,
        public ?array $schema,
        public TextGenerationOptions $options,
        public int $timeoutSeconds,
        public bool $stream,
    ) {}
}
