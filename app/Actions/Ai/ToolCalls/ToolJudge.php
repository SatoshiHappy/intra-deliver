<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\AgentCreateTool;
use App\Actions\Ai\Api\AgentRecordTool;
use App\Actions\Ai\Api\AgentToolParties;
use App\Actions\Ai\Api\AgentToolScope;
use App\Actions\Ai\Api\AgentToolSubject;
use App\Actions\Ai\Api\AgentUpdateTool;
use App\Enums\Ai\AiToolDenialReason;
use App\Enums\Ai\AiToolNature;
use App\Models\Ai\AiRun;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordModuleDirectory;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 判定の関数(docs の 設計/AI/道具と権限/README.md 10.1。表 = 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の表 1 の 2 段目)。
 * 前で決まれば後の口を呼ばないのは、要らない判定の問い合わせを避け、どの断りも見えない物の有無を漏らさないため。呼び手が使い回さない区間の中で呼ぶ
 * (設計/AI/道具と権限/読み書きのたびの今の権限.md 10.4)。読む道具は許し、書く道具は確認の内容を作る口を呼んで問う(B5・B7)。直すは 1 段目で
 * 見つからなければ B6、今の値を使う検証(③ の共通の部品)に当たれば B19 で、2 段目を呼ばない。
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
        private ToolPartyNames $partyNames,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments  形の検証と形の外の検証を通った引数
     * @param  AgentToolParties  $parties  呼ばれた時に引いた相手(作るの確認の内容に渡す)
     *
     * @throws LogicException 呼び出しが指す業務モジュールを道具が答えないとき・性質と確認の内容を作る口が合わないとき(作りの誤り。
     *                        起動の時の確かめが止める。包みの中の例外になる)
     */
    public function judge(
        AgentRecordTool $tool,
        array $arguments,
        GrantedTool $granted,
        AiRun $run,
        AgentToolParties $parties,
    ): ToolJudgement {
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

        return $this->answer($tool, $nature, $arguments, $granted->scope, $subject, $parties);
    }

    /**
     * 権限を通った後の答え(読む = 許す、作る = 問う、直す = 1 段目から)。
     *
     * @param  array<string, mixed>  $arguments
     */
    private function answer(
        AgentRecordTool $tool,
        ?AiToolNature $nature,
        array $arguments,
        AgentToolScope $scope,
        AgentToolSubject $subject,
        AgentToolParties $parties,
    ): ToolJudgement {
        if ($nature === AiToolNature::Read) {
            return ToolJudgement::allow($subject);
        }

        if ($nature === AiToolNature::Create && $tool instanceof AgentCreateTool) {
            return ToolJudgement::ask($tool->confirmation($arguments, $subject, $scope, $parties), null, $arguments);
        }

        return $nature === AiToolNature::Update && $tool instanceof AgentUpdateTool
            ? $this->update($tool, $arguments, $scope, $subject)
            : throw new LogicException("道具 {$tool->name()} の性質と確認の内容を作る口が合わない");
    }

    /**
     * 直すの 1 段目 → 今の値を使う検証 → 2 段目。見つからない(閲覧の判定を通らない・無い・判定の後に消えた。モデルを問わない)は B6。
     *
     * @param  array<string, mixed>  $arguments
     */
    private function update(
        AgentUpdateTool $tool,
        array $arguments,
        AgentToolScope $scope,
        AgentToolSubject $subject,
    ): ToolJudgement {
        try {
            $current = $tool->current($arguments, $subject, $scope);
        } catch (ModelNotFoundException) {
            $current = null;
        }

        if ($current === null) {
            return ToolJudgement::notFound();
        }

        [$written, $rowAccessError] = ToolWriteChecks::afterCurrent($tool, $arguments, $current);
        [$parties, $partyError] = $this->partyNames->afterCurrent($tool, $written, $scope, $current, $subject);
        $errors = array_values(array_filter([$rowAccessError, $partyError], is_string(...)));

        if ($errors !== []) {
            return ToolJudgement::invalidInput(implode("\n", $errors));
        }

        $confirmation = $tool->confirmation($current, $written, $subject, $scope, $parties);

        return ToolJudgement::ask($confirmation, $current->version, $written);
    }
}
