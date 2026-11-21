<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\StopKind;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStepSendKind;
use App\Enums\Ai\AiRunStepStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Queries\Ai\AiSettings;
use App\Queries\Ai\Api\RunBudgetQuery;
use App\Queries\Ai\RunEstimates;
use App\Queries\Ai\RunSpending;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 送る前の確かめの関数(表の正本 = docs の 設計/AI/エージェントの実行/README.md 9.2 の C1〜C6、費用の比べ方と見積もり =
 * 設計/AI/実行と費用/接続先と費用.md 5.3)。費用は使った額だけで比べ、見積もりは比べに足さずにステップの行に書く(使用量が分からないまま
 * 終わったときに数える額)。積む物が無いので予算の鍵を取らない。実行の行は外部キーの確かめと同じ共有の鍵で読み、失敗の書き込みと同時に
 * 処理中のステップの行を足さない。
 */
final readonly class SendCheck
{
    public function __construct(
        private RunSpending $spending,
        private RunEstimates $estimates,
        private AiSettings $settings,
    ) {}

    public function check(SendCheckInput $input): SendCheckOutcome
    {
        return DB::transaction(function () use ($input): SendCheckOutcome {
            /** @var AiRun|null $run */
            $run = AiRun::query()->whereKey($input->runId)->lock('FOR KEY SHARE')->first();

            if ($run === null || $run->status !== AiRunStatus::Running) {
                return SendCheckOutcome::ended();
            }

            return $this->decide($run, $input);
        });
    }

    /**
     * 実行の区分の使った額が上限以上の期間(回の応答を読み終えた後の確かめ = 設計/AI/実行と費用/接続先と費用.md 5.3。比べは送る前と同じ)。
     *
     * @return RunBudgetQuery::DAY|RunBudgetQuery::MONTH|RunBudgetQuery::TOTAL|null
     */
    public function reachedPeriod(int $runId): ?string
    {
        $budget = AiRun::query()->whereKey($runId)->value('budget');

        return $this->spending->reachedPeriod((string) $budget, CarbonImmutable::now());
    }

    /** 結果の優先と、会話の題が見る物の正本 = 設計/AI/エージェントの実行/README.md 9.2 の表 */
    private function decide(AiRun $run, SendCheckInput $input): SendCheckOutcome
    {
        $loop = $input->kind !== SendKind::Title;

        if ($loop && $run->stop_requested_at !== null) {
            return SendCheckOutcome::stop(StopKind::User);
        }

        if ($loop && $input->timedOut) {
            return SendCheckOutcome::stop(StopKind::TimeLimit);
        }

        $now = CarbonImmutable::now();
        $estimate = $this->estimate($input);
        $period = $this->spending->reachedPeriod((string) $run->budget, $now);

        if ($period !== null) {
            return $loop ? SendCheckOutcome::stop(StopKind::CostLimit, $period) : SendCheckOutcome::notSent();
        }

        return SendCheckOutcome::send($this->addStep($run, $input, $estimate, $now));
    }

    /** 式の正本 = 設計/AI/実行と費用/接続先と費用.md 5.3 の「見積もり」(会話の題はキャッシュに書かない = 設計/AI/会話の中核の口と受け手.md 13.3) */
    private function estimate(SendCheckInput $input): string
    {
        $tokens = min((int) ceil($input->chars * $input->traits->estimateFactor), $input->sendLimit);
        $overhead = $input->kind === SendKind::Title ? $this->settings->overheadTokens() : 0;
        $cached = $input->kind !== SendKind::Title
            && ($input->traits->cachesInstructions || $input->traits->cachesHistory);

        $output = $input->outputLimit;

        return $this->estimates->stepEstimate($input->provider, $input->model, $tokens + $overhead, $output, $cached);
    }

    private function addStep(AiRun $run, SendCheckInput $input, string $estimate, CarbonImmutable $now): int
    {
        $sequence = (int) AiRunStep::query()->where('ai_run_id', $run->id)->max('sequence') + 1;

        $step = AiRunStep::query()->create([
            'ai_run_id' => $run->id,
            'run_kind_type' => $run->kind_type,
            'sequence' => $sequence,
            'purpose' => $input->purpose,
            'send_kind' => AiRunStepSendKind::from($input->kind->value),
            'provider' => $input->provider,
            'model' => $input->model,
            'reserved_cost_usd' => $estimate,
            'status' => AiRunStepStatus::Running,
            'started_at' => $now,
        ]);

        return $step->id;
    }
}
