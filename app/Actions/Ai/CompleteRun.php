<?php

namespace App\Actions\Ai;

use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 実行の完了の書き込み(内部。文章と文字起こしの共通の順の正本 = docs の 設計/AI/実行と費用.md 4 節)。1 つのトランザクションで、
 * 種類の完了の受け手を呼び、その後に実行の行の鍵を取って使用量・費用・原文を書き、成功にする(画面が成功を読んだとき受け手の書き込みも見える)。
 * 実行の行の鍵を受け手の後に取るのは、処理中の対象のレコードを消しても完了と削除が待ちの輪を作らないため
 * (設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 4 節)。
 */
class CompleteRun
{
    public function __construct(private CompletingRun $completing) {}

    /**
     * ほかの経路(worker が落ちたときの処理など)が先に終わらせていたら、受け手の書き込みを巻き戻し、使用量・費用・原文だけを書いて false
     * (投げると書いた使用量まで巻き戻るため。作りの誤りとして報告するのは呼び手)。
     *
     * @param  Closure(AiRun): void  $receiver  種類の完了の受け手を呼ぶ(鍵を取らずに読んだ処理中の実行を渡す)
     * @return bool 成功にした = true
     *
     * @throws Throwable 受け手の例外・書き込みの失敗(このトランザクションの書き込みはすべて巻き戻る)
     */
    public function execute(int $runId, Closure $receiver, RunResult $result): bool
    {
        return DB::transaction(function () use ($runId, $receiver, $result): bool {
            $run = AiRun::query()->findOrFail($runId);

            if ($run->status === AiRunStatus::Running && $this->completeInSavepoint($run, $receiver, $result)) {
                return true;
            }

            $this->write(AiRun::query()->lockForUpdate()->findOrFail($runId), $result);

            return false;
        });
    }

    /**
     * セーブポイントの中で受け手を呼び、その後に実行の行の鍵を取る(受け手の書き込みだけを巻き戻せるよう)。running でなくなっていたら false。
     *
     * @param  Closure(AiRun): void  $receiver
     */
    private function completeInSavepoint(AiRun $run, Closure $receiver, RunResult $result): bool
    {
        DB::beginTransaction();

        try {
            $this->completing->during($run->id, fn () => $receiver($run));
            $locked = AiRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->status !== AiRunStatus::Running) {
                DB::rollBack();

                return false;
            }

            $this->write($locked, $result, AiRunStatus::Succeeded);
            DB::commit();

            return true;
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * 鍵を取った実行に使用量・単価・費用と応答の原文を書く(状態を渡したときは終わりにする)。
     */
    private function write(AiRun $run, RunResult $result, ?AiRunStatus $finishedAs = null): void
    {
        $run->fill([
            ...$result->usage,
            ...($finishedAs === null ? [] : ['status' => $finishedAs, 'finished_at' => now()]),
        ])->save();

        if ($result->outputText !== null) {
            AiRunText::query()->whereKey($run->id)->update(['output_text' => $result->outputText]);
        }
    }
}
