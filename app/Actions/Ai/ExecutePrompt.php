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
 * 完了の書き込みの順(受け手 → 実行の行の鍵)は {@see CompleteRun}。依頼者が当日に有効でなければ受け手を呼ばずに completion にする
 * (受け手に渡す依頼者の文脈を作るときに確かめる = 設計/AI/公開の口.md 3.2 の「依頼者の文脈」)。
 */
class ExecutePrompt
{
    public function __construct(
        private PromptKinds $kinds,
        private LlmClient $llm,
        private RunCosts $costs,
        private FailRun $failRun,
        private CompleteRun $completeRun,
        private RunRequesterContext $requesters,
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

        $runResult = $this->costs->promptResult($run->provider, $run->model, $result);

        if ($result->isIncomplete()) {
            $incomplete = new RuntimeException("AI の出力が上限のトークンで切れたか、接続先が応答を断った(終わりの理由 {$result->finishReason})");
            $this->failRun->execute($runId, AiRunErrorCode::Incomplete, $incomplete, $runResult);

            throw $incomplete;
        }

        try {
            $completed = $this->completeRun->execute($runId, fn (AiRun $running) => $kind->complete(new CompletedRun(
                $running->id,
                $running->kind,
                $running->record_id,
                $running->requester_user_id,
                $result->text,
                $result->structured,
                $this->requesters->of($running),
            )), $runResult);
        } catch (Throwable $exception) {
            $this->failRun->execute($runId, AiRunErrorCode::Completion, $exception, $runResult);

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
}
