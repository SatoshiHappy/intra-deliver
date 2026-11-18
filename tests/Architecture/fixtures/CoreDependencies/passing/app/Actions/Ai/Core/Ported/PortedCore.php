<?php

namespace App\Actions\Ai\Core\Ported;

use App\Actions\Ai\Core\AllowedContract;
use App\Actions\Ai\Core\Ported\Sdk\PortedConversion;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\FinishReason;

/**
 * 移した版が依れる物(約束・その版の中・1 ステップの口と値の型・メッセージ・応答の値の型・illuminate/support のファサードでない物)
 */
final class PortedCore
{
    /**
     * @param  Collection<int, UserMessage>  $messages
     */
    public function __construct(
        public AllowedContract $contract,
        public PortedConversion $conversion,
        public StepTextGateway $gateway,
        public Collection $messages,
        public ?TextGenerationOptions $options,
    ) {}

    public function context(): StepContext
    {
        return new StepContext;
    }

    public function finish(StepResponse $response): bool
    {
        return $response->finishReason === FinishReason::Stop;
    }
}
