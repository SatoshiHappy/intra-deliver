<?php

namespace App\Queries\Ai\Api;

use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Models\Ai\AiRun;
use App\Queries\Ai\BudgetMessages;
use App\Queries\Ai\ConversationRunQuery;
use App\Queries\Ai\RunVisibility;
use App\Queries\Authz\Api\Requester;
use App\Queries\Master\Api\MasterNames;
use DateTimeInterface;
use LogicException;

/**
 * AI の実行の閲覧(判定と口の保証の正本 = docs の 設計/AI/公開の口.md 3 節の閲覧の規則と表の「実行の読み取り」の行。判定 = {@see RunVisibility})。
 */
class RunQuery
{
    public function __construct(
        private RunVisibility $visibility,
        private MasterNames $names,
        private ConversationRunQuery $conversations,
    ) {}

    /**
     * 実行の状態の一覧(実行の id → 状態の文字列 queued・running・succeeded・failed、会話の型だけ awaiting_confirmation・stopped)。
     * 主体の判定をしない(呼び手が対象のレコードの閲覧を判定済み。判定の正本 = docs の 設計/AI/公開の口.md 3 節の表の「実行の状態の一覧」の行)。無い id は結果に入らない。空の一覧は問い合わせない。
     *
     * @param  list<int>  $runIds
     * @return array<int, string>
     */
    public function statusesOf(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        $statuses = [];

        foreach (AiRun::query()->whereIn('id', $runIds)->get(['id', 'status']) as $run) {
            $statuses[$run->id] = $run->status->value;
        }

        return $statuses;
    }

    /**
     * 実行の入力の音声(文字起こしの実行の、今の音声の添付の台帳の id)の一覧(実行の id → upload の id)。主体の判定をしない({@see self::statusesOf()} と
     * 同じ扱い)。無い実行・音声の無い実行(文章の実行・音声の台帳の行が消された実行)は結果に入らない。空の一覧は問い合わせない。
     *
     * @param  list<int>  $runIds
     * @return array<int, int>
     */
    public function inputUploadIdsOf(array $runIds): array
    {
        if ($runIds === []) {
            return [];
        }

        $uploadIds = [];

        $runs = AiRun::query()->whereIn('id', $runIds)->whereNotNull('input_upload_id')->get(['id', 'input_upload_id']);

        foreach ($runs as $run) {
            $uploadIds[$run->id] = (int) $run->input_upload_id;
        }

        return $uploadIds;
    }

    /**
     * 実行の表示。見られない・無ければ null(入口が 404 にする)。
     *
     * @param  DateTimeInterface  $date  基準日(業務モジュールの read の判定と依頼者の表示名に使う)
     * @param  string  $localeCode  依頼者の表示名のロケール
     */
    public function show(Requester $requester, int $runId, DateTimeInterface $date, string $localeCode): ?RunView
    {
        $run = AiRun::query()->find($runId);

        if ($run === null || ! $this->visibility->canView($requester, $run, $date)) {
            return null;
        }

        return new RunView(
            id: $run->id,
            kind: $run->kind,
            status: $run->status,
            errorCode: $run->error_code,
            requester: $this->names->users([$run->requester_user_id], $date, $localeCode)[$run->requester_user_id] ?? null,
            createdAt: $run->created_at?->toIso8601String() ?? '',
            startedAt: $run->started_at?->toIso8601String(),
            finishedAt: $run->finished_at?->toIso8601String(),
            failureMessage: $run->status === AiRunStatus::Failed ? self::failureMessage($run->error_code) : null,
            stopMessage: $run->status === AiRunStatus::Stopped ? self::stopMessage($run) : null,
            conversation: $run->kind_type === AiKindType::Conversation ? $this->conversations->of($run) : null,
        );
    }

    private static function failureMessage(?AiRunErrorCode $errorCode): string
    {
        return match ($errorCode) {
            AiRunErrorCode::Setting => RunView::SETTING_FAILURE_MESSAGE,
            AiRunErrorCode::Confirmation => RunView::CONFIRMATION_FAILURE_MESSAGE,
            default => RunView::FAILURE_MESSAGE,
        };
    }

    /**
     * @throws LogicException 止めた理由が無い・費用の上限の区分と期間の文が無いとき(DB の制約と起動の時の確かめが止める)
     */
    private static function stopMessage(AiRun $run): string
    {
        return match ($run->stop_reason) {
            AiRunStopReason::User => RunView::STOPPED_BY_USER_MESSAGE,
            AiRunStopReason::TimeLimit, AiRunStopReason::TurnLimit => RunView::STOPPED_AT_LIMIT_MESSAGE,
            AiRunStopReason::Length => RunView::STOPPED_FOR_LENGTH_MESSAGE,
            AiRunStopReason::Budget => BudgetMessages::of($run->budget, (string) $run->stop_budget_period)
                ?? throw new LogicException("予算の区分 {$run->budget} の上限を超えたときの文が無い"),
            null => throw new LogicException("止めた実行 {$run->id} に止めた理由が無い"),
        };
    }
}
