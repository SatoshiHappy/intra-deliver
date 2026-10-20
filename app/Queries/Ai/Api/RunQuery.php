<?php

namespace App\Queries\Ai\Api;

use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Queries\Ai\RunVisibility;
use App\Queries\Authz\Api\Requester;
use App\Queries\Master\Api\MasterNames;
use DateTimeInterface;

/**
 * AI の実行の閲覧(判定と口の保証の正本 = docs の 設計/AI/公開の口.md 3 節の閲覧の規則と表の「実行の読み取り」の行。判定 = {@see RunVisibility})。
 */
class RunQuery
{
    public function __construct(private RunVisibility $visibility, private MasterNames $names) {}

    /**
     * 実行の状態の一覧(実行の id → 状態の文字列 queued・running・succeeded・failed)。主体の判定をしない(呼び手が対象のレコードの閲覧を判定済み。
     * 判定の正本 = docs の 設計/AI/公開の口.md 3 節の表の「実行の状態の一覧」の行)。無い id は結果に入らない。空の一覧は問い合わせない。
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
            failureMessage: $run->status === AiRunStatus::Failed ? RunView::FAILURE_MESSAGE : null,
        );
    }
}
