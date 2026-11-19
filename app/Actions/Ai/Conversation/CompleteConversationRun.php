<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationEnded;
use App\Actions\Ai\Api\ConversationKind;
use App\Actions\Ai\CompletingRun;
use App\Actions\Ai\Core\LoopEnding;
use App\Actions\Ai\Core\LoopEndingKind;
use App\Actions\Ai\Core\LoopStop;
use App\Actions\Ai\SumRunSteps;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Models\Ai\AiRun;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * 会話のジョブの 6 の成功・確認待ち・止めたの書き方(順 = docs の 設計/AI/エージェントの実行/README.md 9.1 のジョブの順、受け手 =
 * 設計/AI/エージェントの実行/口と契約.md 9.13 の「完了の受け手」)。受け手をセーブポイントの中で呼んでから実行の行の鍵を取るのは、
 * ほかの経路が先に実行を終えていたときに受け手の書き込みだけを巻き戻すため(文章の型の完了の書き方と同じ)。
 */
final readonly class CompleteConversationRun
{
    public function __construct(
        private CompletingRun $completing,
        private SumRunSteps $sums,
    ) {}

    /**
     * @return bool 書いた = true。ほかの経路が先に終えていた = false(何も書かない)
     *
     * @throws LogicException 失敗の終わり方を渡したとき(失敗は失敗の書き方で書く)
     * @throws Throwable 受け手の例外・書き込みの失敗(このトランザクションの書き込みはすべて巻き戻る)
     */
    public function execute(
        int $runId,
        ConversationKind $kind,
        LoopEnding $ending,
        ?string $title,
    ): bool {
        [$status, $reason] = self::statusOf($ending);

        return DB::transaction(function () use ($runId, $kind, $ending, $title, $status, $reason): bool {
            $run = AiRun::query()->findOrFail($runId);

            if ($run->status !== AiRunStatus::Running) {
                return false;
            }

            $ended = new ConversationEnded($run->id, $run->kind, $run->requester_user_id, $status, $reason, $title);
            $values = [
                'status' => $status,
                'stop_reason' => $reason,
                'stop_budget_period' => $reason === AiRunStopReason::Budget ? $ending->stopReason : null,
            ];

            return $this->inSavepoint($run->id, fn () => $kind->completed($ended), $values);
        });
    }

    /**
     * セーブポイントの中で受け手を呼び、その後に実行の行の鍵を取る。処理中でなくなっていたら受け手の書き込みを巻き戻して false。
     *
     * @param  callable(): void  $receiver
     * @param  array<string, mixed>  $values
     */
    private function inSavepoint(int $runId, callable $receiver, array $values): bool
    {
        DB::beginTransaction();

        try {
            $this->completing->during($runId, $receiver(...));
            $locked = AiRun::query()->lockForUpdate()->findOrFail($runId);

            if ($locked->status !== AiRunStatus::Running) {
                DB::rollBack();

                return false;
            }

            $locked->fill([...$values, 'finished_at' => now(), ...$this->sums->columns($locked)])->save();
            DB::commit();

            return true;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * @return array{AiRunStatus, AiRunStopReason|null}
     */
    private static function statusOf(LoopEnding $ending): array
    {
        return match ($ending->kind) {
            LoopEndingKind::Succeeded => [AiRunStatus::Succeeded, null],
            LoopEndingKind::AwaitingConfirmation => [AiRunStatus::AwaitingConfirmation, null],
            LoopEndingKind::Stopped => [AiRunStatus::Stopped, self::reasonOf($ending->stop)],
            LoopEndingKind::Failed => throw new LogicException('失敗の終わり方は失敗の書き方で書く'),
        };
    }

    private static function reasonOf(?LoopStop $stop): AiRunStopReason
    {
        return match ($stop) {
            LoopStop::User => AiRunStopReason::User,
            LoopStop::TimeLimit => AiRunStopReason::TimeLimit,
            LoopStop::TurnLimit => AiRunStopReason::TurnLimit,
            LoopStop::Length => AiRunStopReason::Length,
            LoopStop::CostLimit => AiRunStopReason::Budget,
            null => throw new LogicException('止めたのに止めた区分が無い'),
        };
    }
}
