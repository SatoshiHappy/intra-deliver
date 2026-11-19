<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CoreChecker;
use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\Substitute\Sdk\SubstituteReader;

/**
 * 1 回の試しの読みを、送信の受け先と読む途中の確かめにつなぐ(docs の 設計/AI/会話の中核.md 13.5 の応答を読む途中の位置)。
 */
final class SubstituteListener implements SubstituteReader
{
    public ?string $stopReason = null;

    public function __construct(
        private readonly CoreChecker $checker,
        private readonly SendKind $kind,
        private readonly SubstituteSink $sink,
        private readonly string $responseId,
        private readonly int $stepId,
    ) {}

    public function delta(string $text): void
    {
        $this->sink->delta($this->stepId, $text);
    }

    public function confirmed(array $blocks): void
    {
        $this->sink->confirmed($this->responseId, $this->stepId, $blocks);
    }

    public function stopsWhileReading(): bool
    {
        $reply = SubstituteChecks::at($this->checker, CheckPoint::WhileReading, $this->kind);
        $this->stopReason = $reply->reason;

        return $reply->stops();
    }
}
