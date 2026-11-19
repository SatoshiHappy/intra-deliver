<?php

namespace App\Actions\Ai\Api;

use App\Actions\Ai\ConversationKinds;
use App\Actions\Ai\SumRunSteps;
use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Models\Ai\AiRun;
use App\Support\AccessContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 止める口(docs の 設計/AI/公開の口.md 3 節の止めるの行、振る舞い = 設計/AI/エージェントの実行/道具の包みと止める.md 9.5、無しと作りの誤りの
 * 優先 = 設計/AI/エージェントの実行/口と契約.md 9.12)。① の止める業務処理が会話の行の鍵の下で呼ぶ(鍵の順 = 会話の行 → 実行の行)。
 * 待ちの実行を止めた時は完了の受け手を呼ばず、題も作らない。
 */
final readonly class StopConversationRun
{
    public function __construct(private ConversationKinds $kinds, private SumRunSteps $sums) {}

    /**
     * @return StopOutcome|null 依頼者の実行でない・無いとき null
     *
     * @throws LogicException 会話の型でない実行・答えの実行(書く道具の承認と一緒に扱う)・種類の実装が無いとき(作りの誤り)
     */
    public function execute(AccessContext $context, int $runId): ?StopOutcome
    {
        return DB::transaction(function () use ($context, $runId): ?StopOutcome {
            $run = AiRun::query()->lockForUpdate()->find($runId);

            if ($run === null || $context->userId === null || $run->requester_user_id !== $context->userId) {
                return null;
            }

            if ($run->kind_type !== AiKindType::Conversation) {
                throw new LogicException("AI の実行 {$runId} は会話の型でない");
            }

            return match (true) {
                $run->status->isFinished() => StopOutcome::AlreadyFinished,
                $run->status === AiRunStatus::Running => $this->mark($run),
                default => $this->stopQueued($run),
            };
        });
    }

    private function mark(AiRun $run): StopOutcome
    {
        if ($run->stop_requested_at === null) {
            $run->fill(['stop_requested_at' => now()])->save();
        }

        return StopOutcome::Marked;
    }

    /** 文の実行は中断の印(道具の途中でない形)を書く。会話が無ければ書く先が無いので書かない */
    private function stopQueued(AiRun $run): StopOutcome
    {
        if ($run->answer !== null) {
            throw new LogicException("会話の実行 {$run->id} は答えの実行(答えは書く道具の承認と一緒に扱う)");
        }

        $kind = $this->kinds->find($run->kind)
            ?? throw new LogicException(ConversationKinds::notRegistered($run->kind));
        $kind->store()->append($run->id, [ConversationItem::interruption(false)]);
        $run->fill([
            'status' => AiRunStatus::Stopped,
            'stop_reason' => AiRunStopReason::User,
            'finished_at' => now(),
            ...$this->sums->columns($run),
        ])->save();

        return StopOutcome::StoppedWhileQueued;
    }
}
