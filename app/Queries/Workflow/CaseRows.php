<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * 一覧の行・関連する案件の行(docs の 設計/ワークフロー/画面.md 1.2 の WorkflowCaseRow、認可と一覧.md 3.2 の行の項目)。閲覧の判定は呼び手が済ませた案件を渡す。
 * 問い合わせの回数は行の数に依らない(フロー・状況({@see CaseOperationDecider::situationsOf()}。現在のノードを含む)・申請者の名前・行の can をまとめて引く)。
 *
 * @phpstan-import-type Person from WorkflowPeople
 *
 * @phpstan-type CaseRow array{id: int, number: string|null, name: string, flowName: string, applicant: Person, appliedAt: string|null, currentSummary: array{state: string, name: string}|null, state: string, completionReason: string|null, revision: int, can: array{pullBack: bool, reapply: bool, withdraw: bool, editDraft: bool, deleteDraft: bool}, pullBackNodes: list<array{id: int, name: string, blockedReason: string|null}>}
 */
class CaseRows
{
    public function __construct(
        private CaseOperationDecider $decider,
        private WorkflowPeople $people,
    ) {}

    /**
     * 渡した案件の行(渡した順)。申請者の名前は d・ロケールの名前。
     *
     * @param  Collection<int, WorkflowCase>  $cases
     * @return list<CaseRow>
     */
    public function rows(Requester $requester, DateTimeInterface $date, string $localeCode, Collection $cases): array
    {
        if ($cases->isEmpty()) {
            return [];
        }

        $cases->loadMissing('workflowFlow');
        $situations = $this->decider->situationsOf($requester, $date, array_values($cases->all()));
        $applicants = $this->people->users(array_values($cases->map(fn (WorkflowCase $case) => $case->user_id)->all()), $date, $localeCode);
        $cans = $this->decider->rowCansOf($requester, $date, array_values($situations));

        return array_values($cases->map(fn (WorkflowCase $case) => [
            'id' => $case->id,
            'number' => $case->case_number === null ? null : CaseNumbers::format($case->case_number),
            'name' => self::nameOf($case),
            'flowName' => self::flowOf($case)->name,
            'applicant' => $applicants[$case->user_id],
            'appliedAt' => $case->applied_at?->toIso8601String(),
            'currentSummary' => self::currentSummaryOf($situations[$case->id]),
            'state' => $case->state->value,
            'completionReason' => $case->completion_reason?->value,
            'revision' => $case->revision,
            'can' => $cans[$case->id],
            'pullBackNodes' => self::pullBackNodesOf($situations[$case->id]),
        ])->all());
    }

    /**
     * 判定を求める者が引戻せるノード(ルートの深さ/画面.md 1.2 の pullBackNodes。写した並び)。
     * 案件が未完了でなければ空(引戻しの表 = 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節)。blockedReason = 申請者承認防止で引戻せないときの文言(ダイアログが「決定」を押せなくする =
     * 画面.md 6 節)。
     *
     * @return list<array{id: int, name: string, blockedReason: string|null}>
     */
    public static function pullBackNodesOf(CaseSituation $situation): array
    {
        if ($situation->case->state !== WorkflowCaseState::Active) {
            return [];
        }

        return array_map(fn (int $id, array $node) => ['id' => $id, ...$node], array_keys($situation->pullBackNodes), array_values($situation->pullBackNodes));
    }

    /**
     * 案件名(一時保存は入力の案件名、無ければフロー名)。一括の結果の行も同じ名前を出す。
     */
    public static function nameOf(WorkflowCase $case): string
    {
        $name = $case->state === WorkflowCaseState::Draft ? ($case->draft_input['name'] ?? null) : $case->name;

        return is_string($name) && $name !== '' ? $name : self::flowOf($case)->name;
    }

    /**
     * 読み込んだ案件のフロー(読み込んでいなければ呼び手の誤り)。
     */
    public static function flowOf(WorkflowCase $case): WorkflowFlow
    {
        return $case->workflowFlow ?? throw new LogicException("案件 {$case->id} のフローを読み込んでいない");
    }

    /**
     * 現在のノードの要約(一覧の行の札・現在のステップ、ホームの節の未処理の表の状態・進行中の申請の note。認可と一覧.md 2 節): 状態 = 札の規則
     * ({@see CaseSituation::labelState()} = {@see CurrentNodes::labelStateOf()})、名前 = {@see CaseSituation::currentNames()}。現在のノードが無ければ null。
     *
     * @return array{state: string, name: string}|null
     */
    public static function currentSummaryOf(CaseSituation $situation): ?array
    {
        $state = $situation->labelState();
        $names = $situation->currentNames();

        return $state === null || $names === null ? null : ['state' => $state->value, 'name' => $names];
    }
}
