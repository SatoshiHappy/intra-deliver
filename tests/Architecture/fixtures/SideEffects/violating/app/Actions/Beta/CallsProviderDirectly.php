<?php

namespace App\Actions\Beta;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * 台帳②で AI の呼び出しを許していないモジュール(Beta)の業務処理から、エージェントを通さずに接続先と Gateway を呼ぶ
 */
final class CallsProviderDirectly
{
    /**
     * @param  list<mixed>  $arguments
     */
    public function ask(AgentPrompt $prompt, Dispatcher $events, array $arguments): void
    {
        Ai::textProvider('anthropic')->prompt($prompt);
        (new AnthropicGateway($events))->generateTextStep(...$arguments);
    }
}
