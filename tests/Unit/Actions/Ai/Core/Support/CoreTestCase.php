<?php

namespace Tests\Unit\Actions\Ai\Core\Support;

use App\Actions\Ai\Core\ModelSpec;
use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\SendTarget;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Providers\AnthropicProvider;
use Tests\Unit\Actions\Ai\Core\EmptyContainerTestCase;

/**
 * 中核のテストの土台(送る先 = SDK の接続先に、SDK の差し替えの口で偽の 1 ステップの口を入れた物)。
 */
abstract class CoreTestCase extends EmptyContainerTestCase
{
    protected function target(
        FakeStepGateway $gateway,
        ?ProviderTraits $traits = null,
        string $model = 'claude-x',
        int $window = 200000,
        int $maxOutput = 32000,
    ): SendTarget {
        $provider = new AnthropicProvider($this->createStub(Gateway::class), [], $this->createStub(Dispatcher::class));
        $provider->useTextGateway($gateway);
        $spec = new ModelSpec($model, $window, $maxOutput, null, false);

        return new SendTarget($provider, 'anthropic', $spec, $traits ?? new ProviderTraits, 60);
    }
}
