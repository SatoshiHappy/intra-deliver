<?php

namespace App\Actions\Ai;

use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\RunCompletionFailed;
use App\Models\Ai\AiRun;
use App\Queries\Master\Api\EffectiveUsers;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * worker の中の依頼者の判定の入力を実行の記録から作る 1 か所(要求が無いため。docs の 設計/AI/公開の口.md 3.2 の「依頼者の判定の入力」)。
 * 書き込みの口と、worker の中の 2 段目の依頼が使う。
 */
class RunRequesterContext
{
    public function __construct(private EffectiveUsers $effectiveUsers) {}

    /**
     * 判定の入力 = 公開の口.md 3.2 の「依頼者の判定の入力」の表。依頼者が当日に有効でなければ断る
     * (判定は無効な利用者をゲストとして扱うので、人の要求の入口と同じく前で外す)。
     *
     * @throws RunCompletionFailed 依頼者が当日に有効でないとき(理由 {@see FailedRun::REQUESTER_INACTIVE})
     */
    public function of(AiRun $run): AccessContext
    {
        $date = CarbonImmutable::today(AccessContext::TIMEZONE);

        if ($this->effectiveUsers->idsOn([$run->requester_user_id], $date) === []) {
            throw new RunCompletionFailed(
                FailedRun::REQUESTER_INACTIVE,
                "AI の実行 {$run->id} の依頼者 {$run->requester_user_id} が {$date->toDateString()} に有効でない",
            );
        }

        return new AccessContext(
            userId: $run->requester_user_id,
            ipv4Address: null,
            selectedDepartmentId: $run->selected_department_id,
            date: $date,
            localeCode: AccessContext::LOCALE_CODE,
        );
    }
}
