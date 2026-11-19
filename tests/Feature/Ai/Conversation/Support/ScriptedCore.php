<?php

namespace Tests\Feature\Ai\Conversation\Support;

use App\Actions\Ai\Core\CompactThresholds;
use App\Actions\Ai\Core\ConversationCore;
use App\Actions\Ai\Core\LoopOutcome;
use App\Actions\Ai\Core\LoopRequest;
use App\Actions\Ai\Core\ModelSpec;
use App\Actions\Ai\Core\SendRequest;
use App\Actions\Ai\Core\SendResult;
use LogicException;
use Throwable;

/**
 * 決めた結果を返す中核(繋ぎのテストが、中核に渡した物と、中核の返す物ごとの繋ぎの扱いを見るため)。
 */
final class ScriptedCore implements ConversationCore
{
    /** @var list<SendRequest> */
    public array $sent = [];

    /** @var list<LoopRequest> */
    public array $loops = [];

    public function __construct(
        private ?SendResult $sendResult = null,
        private ?LoopOutcome $loopOutcome = null,
        private ?Throwable $loopException = null,
    ) {}

    public function send(SendRequest $request): SendResult
    {
        $this->sent[] = $request;

        return $this->sendResult ?? throw new LogicException('送信の結果を決めていない');
    }

    public function loop(LoopRequest $request): LoopOutcome
    {
        $this->loops[] = $request;

        if ($this->loopException !== null) {
            throw $this->loopException;
        }

        return $this->loopOutcome ?? throw new LogicException('ループの結果を決めていない');
    }

    public function thresholds(ModelSpec $model): CompactThresholds
    {
        throw new LogicException('繋ぎのテストでは使わない');
    }
}
