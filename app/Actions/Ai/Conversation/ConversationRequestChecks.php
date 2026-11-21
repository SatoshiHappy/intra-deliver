<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\RunRequestDeclined;
use App\Actions\Ai\CompletingRun;
use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use LogicException;

/**
 * 会話の依頼の口の確かめ(表 = docs の 設計/AI/公開の口.md 3 節、前の実行の確かめ = 設計/AI/エージェントの実行/口と契約.md 9.12)。
 */
final readonly class ConversationRequestChecks
{
    public const PREVIOUS_RUNNING = '前の依頼をまだ処理しています';

    public const CONFIRMATION_GONE = 'この確認はもう使えません。もう一度頼んでください';

    public const CONTINUED = 'この会話はほかの操作で先に続いています。開き直してください';

    public const SETTING = 'AI エージェントの設定を使えません。システム管理者に連絡してください';

    public function __construct(private CompletingRun $completing, private DefinitionToolSize $size) {}

    /**
     * 鍵の前の作りの誤り(B8・B10)。前の実行は形だけを見る(状態は鍵の下で {@see self::previous()})。
     *
     * @throws LogicException 完了の受け手の中・定義が無い・前の実行の形が違うとき
     */
    public function shape(int $requesterUserId, int $definitionId, ?int $previousRunId): AiAgentDefinition
    {
        if ($this->completing->runId() !== null) {
            throw new LogicException('会話の型は完了の受け手の中から依頼しない(2 段目の依頼を持たない)');
        }

        $definition = AiAgentDefinition::query()->find($definitionId)
            ?? throw new LogicException("エージェントの定義 {$definitionId} が無い");
        $previous = $previousRunId === null ? null : AiRun::query()->find($previousRunId);
        $wrong = $previousRunId !== null && ($previous === null || $previous->requester_user_id !== $requesterUserId
            || $previous->kind_type !== AiKindType::Conversation);

        if ($wrong) {
            throw new LogicException("前の実行 {$previousRunId} が無いか、依頼者の会話の型の実行でない");
        }

        return $definition;
    }

    /**
     * 鍵の下の前の実行の状態と続く実行(順 = 公開の口.md 3 節の「断る理由が重なったとき」)。呼び手が AI の費用の鍵の後に呼ぶ。
     *
     * @throws RunRequestDeclined 断るとき(422)
     */
    public function previous(int $previousRunId, bool $answers): AiRun
    {
        $previous = AiRun::query()->lockForUpdate()->findOrFail($previousRunId);

        if (in_array($previous->status, [AiRunStatus::Queued, AiRunStatus::Running], true)) {
            throw new RunRequestDeclined(RunRequestDeclined::PREVIOUS_RUNNING, self::PREVIOUS_RUNNING);
        }

        if ($answers && $previous->status !== AiRunStatus::AwaitingConfirmation) {
            throw new RunRequestDeclined(RunRequestDeclined::CONFIRMATION_GONE, self::CONFIRMATION_GONE);
        }

        if (AiRun::query()->where('previous_run_id', $previousRunId)->exists()) {
            throw new RunRequestDeclined(RunRequestDeclined::CONTINUED, self::CONTINUED);
        }

        return $previous;
    }

    /** 定義の外した後の道具の定義の大きさが上限を超えるか(文を送る依頼と確認に答える依頼の両方) */
    public function tooLarge(AiAgentDefinition $definition): bool
    {
        return $this->size->exceeds($definition);
    }

    /**
     * 定義の 422。
     *
     * @throws RunRequestDeclined 上限を超えるとき
     */
    public function throwIfTooLarge(bool $tooLarge): void
    {
        if ($tooLarge) {
            throw new RunRequestDeclined(RunRequestDeclined::SETTING, self::SETTING);
        }
    }
}
