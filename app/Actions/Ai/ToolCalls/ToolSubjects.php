<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentToolSubject;
use App\Models\Ai\AiRun;
use App\Queries\Master\Api\EffectiveUsers;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * 判定の主体の文脈を作る ③ の共通の部品(docs の 設計/AI/道具と権限/道具の判定と今の権限.md 10.4 の表と写し方)。今の依頼者の文脈(公開の口の 3.2)
 * とは別で、訪問者の ID と接続元を持たない。呼び出しごとに、使い回さない区間の中で作る。
 */
class ToolSubjects
{
    public function __construct(private EffectiveUsers $users) {}

    /** 依頼者の今日の文脈(有効かを見ない。1 段目は {@see self::isEffective()} で渡せる範囲を空にする) */
    public function for(AiRun $run): AgentToolSubject
    {
        return self::subject($run, CarbonImmutable::today(AccessContext::TIMEZONE));
    }

    /** 依頼者が今日に有効なら、その文脈(判定の口を呼ぶ前に有効を見る = 10.4 の表のユーザー)。有効でなければ null */
    public function effective(AiRun $run): ?AgentToolSubject
    {
        $date = CarbonImmutable::today(AccessContext::TIMEZONE);
        $userId = $run->requester_user_id;

        return $this->users->idsOn([$userId], $date) === [$userId] ? self::subject($run, $date) : null;
    }

    /** 文脈のユーザーが基準日に有効か */
    public function isEffective(AgentToolSubject $subject): bool
    {
        $userId = $subject->accessContext()->userId;

        return $userId !== null && $this->users->idsOn([$userId], $subject->date()) === [$userId];
    }

    private static function subject(AiRun $run, CarbonImmutable $date): AgentToolSubject
    {
        return new AgentToolSubject(new AccessContext(
            userId: $run->requester_user_id,
            ipv4Address: null,
            selectedDepartmentId: $run->selected_department_id,
            date: $date,
            localeCode: AccessContext::LOCALE_CODE,
        ));
    }
}
