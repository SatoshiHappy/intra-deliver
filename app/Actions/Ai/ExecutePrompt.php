<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\CompletedRun;
use App\Actions\Ai\Api\PromptKind;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * AI の実行を動かす(ジョブ App\Jobs\Ai\RunPromptJob から呼ぶ。手順と失敗の扱いの正本 = docs の 設計/AI/実行と費用.md 4 節、データと失敗と検査.md 7 節)。
 * LLM を待つ間は鍵もトランザクションも持たない(画面と人の操作を止めず、DB の接続を長く掴まないため)。
 * complete と succeeded は 1 つのトランザクションにする(画面が succeeded を読んだとき complete の書き込みも見えるように)。
 * 実行の行の鍵は complete の後に取る(処理中の対象のレコードを消しても、完了と削除が待ちの輪を作らないため =
 * 設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 4 節)。
 */
class ExecutePrompt
{
    public function __construct(
        private PromptKinds $kinds,
        private LlmClient $llm,
        private RunCosts $costs,
        private FailRun $failRun,
        private CompletingRun $completing,
    ) {}

    /**
     * @throws LogicException 実行が queued でない・種類の実装が無いとき
     * @throws Throwable LLM の呼び出しか結果の書き込みの失敗(実行は failed に書いた後)
     */
    public function execute(int $runId): void
    {
        [$run, $text, $kind] = $this->start($runId);

        if ($kind === null) {
            $notRegistered = new LogicException(PromptKinds::notRegistered($run->kind));
            $this->failRun->execute($runId, AiRunErrorCode::Completion, $notRegistered);

            throw $notRegistered;
        }

        try {
            $result = $this->llm->call($run, $text, $kind);
        } catch (LlmCallFailed $failure) {
            $this->failRun->execute($runId, $failure->errorCode, $failure->getPrevious());

            throw $failure;
        }

        if ($result->isIncomplete()) {
            $incomplete = new RuntimeException("AI の出力が上限のトークンで切れたか、接続先が応答を断った(終わりの理由 {$result->finishReason})");
            $this->failRun->execute($runId, AiRunErrorCode::Incomplete, $incomplete, $result);

            throw $incomplete;
        }

        try {
            $completed = DB::transaction(fn (): bool => $this->complete($runId, $kind, $result));
        } catch (Throwable $exception) {
            $this->failRun->execute($runId, AiRunErrorCode::Completion, $exception, $result);

            throw $exception;
        }

        if (! $completed) {
            report(new LogicException("AI の実行 {$runId} は応答の前にほかの経路で終わっていた(使用量だけを書いた)"));
        }
    }

    /**
     * 実行を running にし、送る文と種類の実装と一緒に返す。種類の実装が引けなければ状態を変えない(呼び手が completion にする)。
     *
     * @return array{AiRun, AiRunText, PromptKind|null}
     *
     * @throws LogicException 実行が queued でない・送る文が無いとき
     */
    private function start(int $runId): array
    {
        return DB::transaction(function () use ($runId): array {
            $run = AiRun::query()->lockForUpdate()->findOrFail($runId);

            if ($run->status !== AiRunStatus::Queued) {
                throw new LogicException("AI の実行 {$runId} は queued でない({$run->status->value})");
            }

            $text = AiRunText::query()->find($runId) ?? throw new LogicException("AI の実行 {$runId} の送る文が無い");
            $kind = $this->kinds->find($run->kind);

            if ($kind !== null) {
                $run->fill(['status' => AiRunStatus::Running, 'started_at' => now()])->save();
            }

            return [$run, $text, $kind];
        });
    }

    /**
     * 完了の書き込み(正本 = 設計/AI/実行と費用.md 4 節)。ほかの経路が先に終わらせていたら、complete の書き込みを巻き戻し、
     * 使用量と原文だけを書いて false(投げると書いた使用量まで巻き戻るため)。
     */
    private function complete(int $runId, PromptKind $kind, LlmResult $result): bool
    {
        $run = AiRun::query()->findOrFail($runId);

        if ($run->status === AiRunStatus::Running && $this->completeInSavepoint($run, $kind, $result)) {
            return true;
        }

        $this->writeResult(AiRun::query()->lockForUpdate()->findOrFail($runId), $result);

        return false;
    }

    /**
     * セーブポイントの中で {@see PromptKind::complete()} を呼び、その後に実行の行の鍵を取る(受け手の書き込みだけを巻き戻せるよう。
     * 鍵の順の理由はクラスの PHPDoc)。
     * running でなくなっていたら false。
     */
    private function completeInSavepoint(AiRun $run, PromptKind $kind, LlmResult $result): bool
    {
        DB::beginTransaction();

        try {
            $completed = new CompletedRun(
                $run->id,
                $run->kind,
                $run->record_id,
                $run->requester_user_id,
                $result->text,
                $result->structured,
            );
            $this->completing->during($run->id, fn () => $kind->complete($completed));
            $locked = AiRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->status !== AiRunStatus::Running) {
                DB::rollBack();

                return false;
            }

            $this->writeResult($locked, $result, AiRunStatus::Succeeded);
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
    private function writeResult(AiRun $run, LlmResult $result, ?AiRunStatus $finishedAs = null): void
    {
        $run->fill([
            ...$this->costs->usageAttributes($run->provider, $run->model, $result),
            ...($finishedAs === null ? [] : ['status' => $finishedAs, 'finished_at' => now()]),
        ])->save();
        AiRunText::query()->whereKey($run->id)->update(['output_text' => $result->text]);
    }
}
