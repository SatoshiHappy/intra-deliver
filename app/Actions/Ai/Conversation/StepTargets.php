<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\SendKind;

/**
 * 送信の種別から用途の送る先を引く(用途の正本 = docs の 設計/AI/実行と費用/接続先と費用.md 5.3)。
 */
final readonly class StepTargets
{
    public function __construct(
        public StepTarget $conversation,
        public StepTarget $title,
    ) {}

    public function for(SendKind $kind): StepTarget
    {
        return $kind === SendKind::Title ? $this->title : $this->conversation;
    }
}
