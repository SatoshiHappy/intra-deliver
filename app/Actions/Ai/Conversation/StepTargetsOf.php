<?php

namespace App\Actions\Ai\Conversation;

use App\Enums\Ai\AiConversationPurpose;
use App\Models\Ai\AiRun;
use LogicException;

/**
 * 実行の記録に写した用途の接続先とモデルと、その接続先の性質から、送る先を組む(写す値 = docs の 設計/AI/実行の記録.md 2.1 の
 * 「用途ごとの接続先とモデル・出力の上限」、用途 = 設計/AI/実行と費用/接続先と費用.md 5 節の「会話の型の用途」)。
 */
final readonly class StepTargetsOf
{
    public function __construct(private ProviderTraitsOf $traits) {}

    /**
     * @throws LogicException 実行の記録に用途の写しが無いとき(処理中にする時に写す)
     */
    public function targets(AiRun $run): StepTargets
    {
        return new StepTargets(
            $this->target($run, AiConversationPurpose::Conversation),
            $this->target($run, AiConversationPurpose::Title),
        );
    }

    private function target(AiRun $run, AiConversationPurpose $purpose): StepTarget
    {
        $snapshot = $run->purposes[$purpose->value] ?? null;
        $provider = is_array($snapshot) && is_string($snapshot['provider'] ?? null) ? $snapshot['provider'] : null;
        $model = is_array($snapshot) && is_string($snapshot['model'] ?? null) ? $snapshot['model'] : null;

        if ($provider === null || $model === null) {
            throw new LogicException("実行 {$run->id} に用途 {$purpose->value} の接続先とモデルの写しが無い");
        }

        return new StepTarget($purpose, $provider, $model, $this->traits->of($provider, $model));
    }
}
