<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentRecordTool;
use App\Enums\Ai\AiToolDenialReason;
use App\Enums\Ai\AiToolNature;
use App\Models\Ai\AiRun;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordModuleDirectory;
use LogicException;

/**
 * 判定の関数(docs の 設計/AI/道具と権限/README.md 10.1。表 = 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の表 1 の 2 段目)。
 * 前で決まれば後の口を呼ばないのは、要らない判定の問い合わせを避け、どの断りも見えない物の有無を漏らさないため。呼び手が使い回さない区間の中で呼ぶ(10.4)。
 */
class ToolJudge
{
    /** 規則による断りに添える理由の文 */
    public const OUT_OF_SCOPE = 'この業務モジュールは、この依頼で使える範囲の外にある。';

    public const INACTIVE = '依頼した利用者は、今日は有効でない。';

    public const NO_PERMISSION = '依頼した利用者は、この業務モジュールでこの操作をする権限を持たない。';

    public function __construct(
        private RecordModuleDirectory $directory,
        private RecordFormQuery $forms,
        private ToolSubjects $subjects,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments  形の検証と形の外の検証を通った引数
     *
     * @throws LogicException 呼び出しが指す業務モジュールを道具が答えないとき・書く道具で許すに当たるとき(作りの誤り。包みの中の例外になる)
     */
    public function judge(AgentRecordTool $tool, array $arguments, GrantedTool $granted, AiRun $run): ToolJudgement
    {
        $module = $tool->moduleOf($arguments) ?? throw new LogicException("道具 {$tool->name()} が呼び出しの業務モジュールを答えない");
        $name = array_column($this->directory->topLevelModules(), 'name', 'code')[$module] ?? null;

        if ($name === null || $this->directory->visibilityStopReason($module) !== null) {
            return ToolJudgement::notFound();
        }

        $denied = fn (AiToolDenialReason $reason, string $text): ToolJudgement => ToolJudgement::deniedByRule(
            $reason,
            "業務モジュール {$name}: {$text}",
        );

        if (! $granted->scope->has($module)) {
            return $denied(AiToolDenialReason::OutOfScope, self::OUT_OF_SCOPE);
        }

        $subject = $this->subjects->effective($run);

        if ($subject === null) {
            return $denied(AiToolDenialReason::Inactive, self::INACTIVE);
        }

        $nature = AiToolNature::of($tool->isReadOnly(), $tool->requiredAction());

        foreach ($nature?->requiredActions() ?? [] as $action) {
            if (! $this->forms->allows($subject->requester(), $module, $action, $subject->date())) {
                return $denied(AiToolDenialReason::NoPermission, self::NO_PERMISSION);
            }
        }

        return $nature === AiToolNature::Read
            ? ToolJudgement::allow($subject)
            : throw new LogicException("道具 {$tool->name()} は読む道具でない(書く道具の判定はまだ無い)");
    }
}
