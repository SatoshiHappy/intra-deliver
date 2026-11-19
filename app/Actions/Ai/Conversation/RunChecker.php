<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\CheckPoint;
use App\Actions\Ai\Core\CheckReply;
use App\Actions\Ai\Core\CheckRequest;
use App\Actions\Ai\Core\CoreChecker;
use App\Actions\Ai\Core\StopKind;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use LogicException;

/**
 * 確かめの口の中身(位置ごとの確かめの正本 = docs の 設計/AI/エージェントの実行/README.md 9.2 の表、位置と返り値 =
 * 設計/AI/会話の中核.md 13.5)。止める印は位置ごとに実行の行から読み直す(止める口が別の処理から書くため)。
 */
final readonly class RunChecker implements CoreChecker
{
    public function __construct(
        private int $runId,
        private StepTargets $targets,
        private RunClock $clock,
        private SendCheck $sendCheck,
    ) {}

    /**
     * @throws RunNoLongerRunning 実行がもう処理中でないとき
     */
    public function check(CheckRequest $request): CheckReply
    {
        if ($request->point === CheckPoint::BeforeSend) {
            return $this->beforeSend($request);
        }

        if ($this->stopRequested()) {
            return CheckReply::stop(StopKind::User);
        }

        $timed = $request->point === CheckPoint::BeforeTool || $request->point === CheckPoint::BeforeRetryWait;
        $remaining = $this->clock->remainingMilliseconds();

        if ($timed && $remaining < 0) {
            return CheckReply::stop(StopKind::TimeLimit);
        }

        return $request->point === CheckPoint::BeforeRetryWait
            ? CheckReply::proceedToWait($remaining)
            : CheckReply::proceed();
    }

    private function beforeSend(CheckRequest $request): CheckReply
    {
        $target = $this->targets->for($request->kind);
        $outcome = $this->sendCheck->check(new SendCheckInput(
            $this->runId,
            $target->purpose,
            $request->kind,
            $target->provider,
            $target->model,
            $target->traits,
            (int) $request->chars,
            (int) $request->outputLimit,
            (int) $request->sendLimit,
            $this->clock->exceeded(),
        ));

        return match ($outcome->result) {
            SendCheckResult::Ended => throw new RunNoLongerRunning("実行 {$this->runId} はもう処理中でない"),
            SendCheckResult::Send => CheckReply::proceedToSend(
                $outcome->stepId ?? throw new LogicException('送るのに行が無い'),
            ),
            SendCheckResult::NotSent => CheckReply::stop(StopKind::CostLimit),
            SendCheckResult::Stop => CheckReply::stop(
                $outcome->stop ?? throw new LogicException('止めるのに区分が無い'),
                $outcome->period,
            ),
        };
    }

    /**
     * @throws RunNoLongerRunning 実行がもう処理中でないとき
     */
    private function stopRequested(): bool
    {
        /** @var AiRun|null $run */
        $run = AiRun::query()->whereKey($this->runId)->first(['id', 'status', 'stop_requested_at']);

        if ($run === null || $run->status !== AiRunStatus::Running) {
            throw new RunNoLongerRunning("実行 {$this->runId} はもう処理中でない");
        }

        return $run->stop_requested_at !== null;
    }
}
