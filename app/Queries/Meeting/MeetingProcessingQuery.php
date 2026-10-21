<?php

namespace App\Queries\Meeting;

use App\Enums\Ai\AiRunStatus;
use App\Enums\Meeting\MeetingProcessingState;
use App\Models\Meeting\MeetingRun;
use App\Queries\Ai\Api\RunQuery;
use LogicException;

/**
 * 会議の処理の状態(docs の 設計/業務ツール/会議/処理.md の 3.8)。会議の最後の対応(id が最大の行)と、その実行の状態から読む。
 * 主体の判定をしない(呼び手が会議の閲覧を判定した後に呼ぶ)。
 */
class MeetingProcessingQuery
{
    public function __construct(private RunQuery $runs) {}

    /**
     * @param  list<int>  $recordIds  会議の id
     * @return array<int, MeetingProcessingState> 会議の id → 状態(状態の無い会議は入れない)
     */
    public function statesOf(array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        $lastIds = MeetingRun::query()->selectRaw('max(id)')->whereIn('record_id', $recordIds)->groupBy('record_id');
        $lastRuns = MeetingRun::query()
            ->whereIn('id', $lastIds)
            ->get(['record_id', 'ai_run_id']);
        $statuses = $this->runs->statusesOf(array_values(array_filter($lastRuns->pluck('ai_run_id')->all(), 'is_int')));
        $states = [];

        foreach ($lastRuns as $run) {
            $status = $run->ai_run_id === null ? null : $statuses[$run->ai_run_id] ?? self::missing($run->ai_run_id);
            $state = self::stateOf($status);

            if ($state !== null) {
                $states[$run->record_id] = $state;
            }
        }

        return $states;
    }

    /**
     * @param  string|null  $status  実行の状態(断られた対応 = null)
     */
    private static function stateOf(?string $status): ?MeetingProcessingState
    {
        return match ($status) {
            null, AiRunStatus::Failed->value => MeetingProcessingState::Failed,
            AiRunStatus::Queued->value, AiRunStatus::Running->value => MeetingProcessingState::Processing,
            default => null,
        };
    }

    /**
     * @throws LogicException 実行の行は消えない(外部キーの RESTRICT)ので、無ければ整合の誤り
     */
    private static function missing(int $runId): never
    {
        throw new LogicException("会議の対応が指す AI の実行 {$runId} が無い");
    }
}
