<?php

namespace App\Actions\Ai;

use App\Models\Ai\AiRun;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * worker の中の依頼者の文脈を実行の記録から作る 1 か所(要求が無いため。docs の 設計/AI/公開の口.md 3.2 の「依頼者の文脈」)。
 * 書き込みの口・読む口と、worker の中の 2 段目の依頼が使う(2 段目の依頼はこの文脈で依頼の口を呼ぶので、新しい実行に同じ訪問者の ID が写る)。
 * 依頼者が当日に有効かを確かめない(依頼の時点に呼び手の入口が確かめた = ADR 0029)。判定の入力には渡さない(判定は無効な利用者を
 * ゲストとして扱うため = 同じ 3.2)。
 */
class RunRequesterContext
{
    /**
     * 文脈 = 公開の口.md 3.2 の「依頼者の文脈」の表。
     */
    public function of(AiRun $run): AccessContext
    {
        return new AccessContext(
            userId: $run->requester_user_id,
            ipv4Address: null,
            selectedDepartmentId: $run->selected_department_id,
            date: CarbonImmutable::today(AccessContext::TIMEZONE),
            localeCode: AccessContext::LOCALE_CODE,
            visitorId: $run->visitor_id,
        );
    }
}
