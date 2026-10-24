<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * ホームのワークフローの節の読み取り(docs の 設計/ワークフロー/画面/ホームと意匠とE2E.md 7 節の述語の表)。5 つの節がこれを呼ぶ(同じ要求の中で件数を重ねて読んでよい)。
 * どれも閲覧の判定 {@see CaseVisibility} の述語で絞った案件の中から選ぶ(閲覧できない案件は出ない)。鍵を取らない。例外を投げない
 * (定義・案件が無ければ件数が 0・行が空になる)。「自分」= 判定を求める者のユーザーが d に有効ならそのユーザー。
 *
 * - 未処理 = 案件一覧の未処理のタブと同じ案件。並び = 案件の updated_at の降順、id の降順(一覧と同じ)。
 * - 進行中の申請 = 処理済の述語で絞った案件のうち、未完了(state = active)で申請者が自分。並びは同上。
 * - 今月の処理済み = 自分が処理者の履歴の行のうち、種別が承認・承認終了・否認・差戻しで、処理日時(created_at)が d の月(Asia/Tokyo の d の月の 1 日 0 時以上、
 *   翌月の 1 日 0 時未満)のもの(案件は処理済の述語で絞る)。1 行 = 履歴の 1 行。並び = 履歴の id の降順。
 * - 一時保存 = 案件一覧の一時保存のタブと同じ案件。
 */
class WorkflowHomeQuery
{
    /** 今月の処理済みに数える履歴の種別(承認のノードの処理。申請・再申請・取止め・引戻しは数えない) */
    public const PROCESSED_ACTIONS = [WorkflowAction::Approve, WorkflowAction::ApproveEarly, WorkflowAction::Reject, WorkflowAction::SendBack];

    public function __construct(
        private SubjectGroupMembership $membership,
        private CaseVisibility $visibility,
        private CurrentNodes $currentNodes,
        private CaseTargetTerms $targetTerms,
        private CaseDeadlines $deadlines,
    ) {}

    /**
     * ゲストか(今の利用者が d に有効なユーザーでない = docs の 設計/メニュー/ホーム.md 6.1)。
     */
    public function isGuest(AccessContext $context): bool
    {
        return $this->userIdOf($context) === null;
    }

    /**
     * 未処理の案件(並びの順)。
     *
     * @return Builder<WorkflowCase>
     */
    public function pending(AccessContext $context): Builder
    {
        return $this->ordered($this->visibility->pending(WorkflowCase::query(), Requester::fromAccessContext($context), $context->date));
    }

    /**
     * 進行中の申請(並びの順)。自分がいなければどの案件にも合わない。
     *
     * @return Builder<WorkflowCase>
     */
    public function activeApplications(AccessContext $context): Builder
    {
        return $this->ordered($this->visibility->processed(WorkflowCase::query(), Requester::fromAccessContext($context), $context->date)
            ->where('state', WorkflowCaseState::Active)
            ->where('user_id', $this->userIdOf($context)));
    }

    /**
     * 一時保存の案件。
     *
     * @return Builder<WorkflowCase>
     */
    public function drafts(AccessContext $context): Builder
    {
        return $this->visibility->draft(WorkflowCase::query(), Requester::fromAccessContext($context), $context->date);
    }

    /**
     * 今月の処理済みの履歴の行(並び = 処理日時の降順、同じ秒は id の降順 = docs の 設計/ワークフロー/画面/ホームと意匠とE2E.md 7 節)。自分がいなければどの行にも合わない。
     *
     * @return Builder<WorkflowCaseHistory>
     */
    public function processedThisMonth(AccessContext $context): Builder
    {
        $requester = Requester::fromAccessContext($context);
        $monthStart = CarbonImmutable::parse($context->date->toDateString(), AccessContext::TIMEZONE)->startOfMonth();

        return WorkflowCaseHistory::query()
            ->where('user_id', $this->userIdOf($context))
            ->whereIn('action', self::PROCESSED_ACTIONS)
            ->where('created_at', '>=', $monthStart->utc())
            ->where('created_at', '<', $monthStart->addMonth()->utc())
            ->whereHas('workflowCase', fn (Builder $case) => $this->visibility->processed($case, $requester, $context->date))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * 案件の札の状態ごとの案件の数(状態の値 → 件数。0 件の状態は入らない。件数カードの未処理・進行中の申請の内訳 = 設計/ワークフロー/画面/ホームと意匠とE2E.md 7 節)。案件ごとに一覧の行の札の規則
     * ({@see CurrentNodes::labelStateOf()})で 1 つに数える(内訳の和 = 件数。ルートの深さ/認可と一覧.md 2 節 = H1)。現在のノードは {@see CurrentNodes} で読む。
     *
     * @param  Builder<WorkflowCase>  $cases
     * @return array<string, int>
     */
    public function countsByLabelState(Builder $cases): array
    {
        $counts = [];

        foreach ($this->currentNodes->of(array_values(array_map(intval(...), $cases->clone()->reorder()->pluck('id')->all()))) as $nodes) {
            $state = CurrentNodes::labelStateOf($nodes);

            if ($state !== null) {
                $counts[$state->value] = ($counts[$state->value] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * 未処理の案件のうち、未処理の集まりが期限超過のものの数(件数カードの「期限超過 N」= 設計/ワークフロー/案件の運用/認可と一覧.md の
     * 2.4 節)。期限は一覧の未処理の行と同じ計算({@see CaseDeadlines})。自分がいなければ 0。
     *
     * @param  Builder<WorkflowCase>  $cases  未処理の案件
     */
    public function overdueCount(AccessContext $context, Builder $cases): int
    {
        $userId = $this->userIdOf($context);

        if ($userId === null) {
            return 0;
        }

        $caseIds = array_values(array_map(intval(...), $cases->clone()->reorder()->pluck('id')->all()));
        $nodesByCase = $this->currentNodes->of($caseIds);
        $nodeIds = array_map(fn (CurrentNode $node) => $node->id, array_merge([], ...array_values($nodesByCase)));
        $targetIds = $this->targetTerms->targetNodeIds($nodeIds, $userId, $context->date);
        $overdue = 0;

        foreach ($nodesByCase as $nodes) {
            $pendingNodes = $this->deadlines->pendingNodesOf($nodes, $targetIds);
            $overdue += $this->deadlines->summaryOf($pendingNodes, $context->date)->isOverdue ? 1 : 0;
        }

        return $overdue;
    }

    /**
     * 履歴の行の種別ごとの件数(種別の値 → 件数。0 件の種別は入らない)。
     *
     * @param  Builder<WorkflowCaseHistory>  $histories
     * @return array<string, int>
     */
    public function countsByAction(Builder $histories): array
    {
        return array_map(intval(...), $histories->clone()
            ->reorder()
            ->groupBy('action')
            ->toBase()
            ->selectRaw('action, count(*) as histories')
            ->pluck('histories', 'action')
            ->all());
    }

    private function userIdOf(AccessContext $context): ?int
    {
        return $this->membership->effectiveUserId(Requester::fromAccessContext($context), $context->date);
    }

    /**
     * @param  Builder<WorkflowCase>  $cases
     * @return Builder<WorkflowCase>
     */
    private function ordered(Builder $cases): Builder
    {
        return $cases->orderByDesc('updated_at')->orderByDesc('id');
    }
}
