<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowConfirmationProgress;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseNode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * 確認の開きと確認の状態の 1 か所(docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.5 節、
 * 設計/ワークフロー/案件の運用/データモデル/表.md の 3.11 節)。確認の業務処理・状況の役割(確認対象者)・詳細の確認の面が同じ規則を使う。鍵を取らない。
 *
 * @phpstan-type Steps array{stepNames: list<string>, at: string}
 */
class CaseConfirmations
{
    /**
     * 案件ごとの、その人が確認対象者の開いている確認のノード(未確認・確認済の両方)。問い合わせは案件の数に依らず 1 回。
     *
     * @param  list<int>  $caseIds
     * @return array<int, list<int>> 案件の id → ノードの id(昇順。無い案件は入れない)
     */
    public function openTargetNodeIds(array $caseIds, int $userId): array
    {
        if ($caseIds === []) {
            return [];
        }

        $rows = WorkflowCaseConfirmTarget::query()->from('workflow_case_confirm_targets as targets')
            ->join('workflow_case_nodes as nodes', 'nodes.id', '=', 'targets.workflow_case_node_id')
            ->join('workflow_cases as cases', 'cases.id', '=', 'targets.workflow_case_id')
            ->whereIn('targets.workflow_case_id', $caseIds)
            ->where('targets.user_id', $userId)
            ->where('nodes.state', WorkflowNodeState::Done->value)
            ->whereNotNull('nodes.confirm_authz_subject_group_id')
            ->where('cases.state', WorkflowCaseState::Active->value)
            ->orderBy('targets.workflow_case_id')
            ->orderBy('targets.workflow_case_node_id')
            ->toBase()
            ->get(['targets.workflow_case_id as case_id', 'targets.workflow_case_node_id as node_id']);
        $byCase = [];

        foreach ($rows as $row) {
            $byCase[(int) $row->case_id][] = (int) $row->node_id;
        }

        return $byCase;
    }

    /**
     * 案件ごとの、その人が未確認の確認のノードの名前(写した並び)と、確認が開いた日時の最も古いもの(一覧の未確認の行 =
     * 設計/ワークフロー/案件の運用/認可と一覧.md の 2.2 節。未確認の規則は {@see self::unconfirmedRowsFor()} と同じ)。
     * 開いた日時 = ノードを処理済みにした履歴の行の日時。案件が未完了かは呼び手が見る。問い合わせは案件の数に依らない。
     *
     * @param  list<int>  $caseIds
     * @return array<int, Steps> 案件の id → 確認するステップ(無い案件は入れない)
     */
    public function unconfirmedStepsOf(array $caseIds, int $userId): array
    {
        if ($caseIds === []) {
            return [];
        }

        $nodes = WorkflowCaseNode::query()->with(['parent', 'workflowCaseHistory'])
            ->whereIn('workflow_case_nodes.workflow_case_id', $caseIds)
            ->whereExists($this->unconfirmedRowsFor($userId, 'workflow_case_nodes.workflow_case_id')
                ->whereColumn('confirm_nodes.id', 'workflow_case_nodes.id'))
            ->get();
        $at = fn (WorkflowCaseNode $node) => CarbonImmutable::instance($node->workflowCaseHistory->created_at
            ?? throw new LogicException("処理済みのノード {$node->id} に処理した履歴の行が無い"));

        return self::stepsOf(array_values($nodes->all()), array_map($at, $nodes->keyBy('id')->all()), latest: false);
    }

    /**
     * 案件ごとの、その人が確認したノードの名前(写した並び)と、確認日時の最新のもの(一覧の確認済の行。閉じた確認・完了の後の行も含む)。
     * 問い合わせは案件の数に依らない。
     *
     * @param  list<int>  $caseIds
     * @return array<int, Steps> 案件の id → 確認したステップ(無い案件は入れない)
     */
    public function confirmedStepsOf(array $caseIds, int $userId): array
    {
        if ($caseIds === []) {
            return [];
        }

        $latest = WorkflowCaseConfirmation::query()->whereIn('workflow_case_id', $caseIds)->where('user_id', $userId)
            ->groupBy('workflow_case_node_id')->toBase()
            ->selectRaw('workflow_case_node_id, max(created_at) as confirmed_at')
            ->pluck('confirmed_at', 'workflow_case_node_id')->all();
        $nodes = WorkflowCaseNode::query()->with('parent')->whereIn('id', array_keys($latest))->get();
        $at = array_map(fn (mixed $time) => CarbonImmutable::parse((string) $time), $latest);

        return self::stepsOf(array_values($nodes->all()), $at, latest: true);
    }

    /**
     * ノードを案件ごとに写した並びで集め、名前と日時(最も古いか最新)にする。
     *
     * @param  list<WorkflowCaseNode>  $nodes
     * @param  array<int, CarbonImmutable>  $at  ノードの id → 日時
     * @return array<int, Steps>
     */
    private static function stepsOf(array $nodes, array $at, bool $latest): array
    {
        usort($nodes, WorkflowCaseNode::compareInRoute(...));
        $byCase = [];

        foreach ($nodes as $node) {
            $byCase[$node->workflow_case_id][] = $node;
        }

        return array_map(function (array $caseNodes) use ($at, $latest): array {
            $times = array_map(fn (WorkflowCaseNode $node) => $at[$node->id], $caseNodes);
            $time = $latest ? max($times) : min($times);

            return [
                'stepNames' => array_map(fn (WorkflowCaseNode $node) => $node->displayName(), $caseNodes),
                'at' => $time->toIso8601String(),
            ];
        }, $byCase);
    }

    /**
     * 外の問い合わせの案件の行に掛ける EXISTS の中身: その人の確認対象者の行(閲覧の判定の確認の述語 =
     * 設計/ワークフロー/案件の運用/認可と一覧.md の 1 節。行は確認が初めて開いた時に作り、閉じても残る)。
     *
     * @param  string  $caseColumn  外の問い合わせの案件の id の列
     * @return Builder<WorkflowCaseConfirmTarget>
     */
    public function targetRowsFor(int $userId, string $caseColumn): Builder
    {
        return WorkflowCaseConfirmTarget::query()
            ->whereColumn('workflow_case_confirm_targets.workflow_case_id', $caseColumn)
            ->where('workflow_case_confirm_targets.user_id', $userId);
    }

    /**
     * EXISTS の中身: その人が未確認の、開いている確認の確認対象者の行(ノードが処理済みで確認する人を持ち、ノードの今の履歴の行を指す
     * その人の確認の行が無い = {@see self::stateOf()} の未確認と同じ規則)。案件が未完了かは呼び手が見る。
     *
     * @param  string  $caseColumn  外の問い合わせの案件の id の列
     * @return Builder<WorkflowCaseConfirmTarget>
     */
    public function unconfirmedRowsFor(int $userId, string $caseColumn): Builder
    {
        $confirmed = WorkflowCaseConfirmation::query()->from('workflow_case_confirmations as confirmed')
            ->whereColumn('confirmed.workflow_case_node_id', 'confirm_nodes.id')
            ->whereColumn('confirmed.workflow_case_history_id', 'confirm_nodes.workflow_case_history_id')
            ->where('confirmed.user_id', $userId);
        $target = 'workflow_case_confirm_targets.workflow_case_node_id';

        return $this->targetRowsFor($userId, $caseColumn)
            ->join('workflow_case_nodes as confirm_nodes', 'confirm_nodes.id', '=', $target)
            ->where('confirm_nodes.state', WorkflowNodeState::Done->value)
            ->whereNotNull('confirm_nodes.confirm_authz_subject_group_id')
            ->whereNotExists($confirmed);
    }

    /**
     * EXISTS の中身: その人の確認の行(閉じた確認・完了の後の行も含む)。確認の行は確認対象者の行を指す(複合外部キー)ので、
     * これに合う案件は確認の述語にも合う。
     *
     * @param  string  $caseColumn  外の問い合わせの案件の id の列
     * @return Builder<WorkflowCaseConfirmation>
     */
    public function confirmationRowsFor(int $userId, string $caseColumn): Builder
    {
        return WorkflowCaseConfirmation::query()
            ->whereColumn('workflow_case_confirmations.workflow_case_id', $caseColumn)
            ->where('workflow_case_confirmations.user_id', $userId);
    }

    /**
     * 案件の確認対象者ごとの確認の状態(確認対象者の行を持つノードだけ。確認が初めて開くまでは行が無い =
     * 設計/ワークフロー/案件の運用/データモデル/表.md の 3.10 節)。
     *
     * @return array<int, array<int, WorkflowConfirmationProgress>> ノードの id → 確認対象者のユーザーの id → 状態(どちらも昇順)
     *
     * @throws LogicException 確認対象者の行が確認する人を持たないノードを指すとき(写しの列は変えないので起きない)
     */
    public function statesOf(WorkflowCase $case): array
    {
        $nodes = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)
            ->whereNotNull('confirm_authz_subject_group_id')->get()->keyBy('id');
        $targets = WorkflowCaseConfirmTarget::query()->where('workflow_case_id', $case->id)
            ->orderBy('workflow_case_node_id')->orderBy('user_id')->get();
        $confirmed = [];

        foreach (WorkflowCaseConfirmation::query()->where('workflow_case_id', $case->id)->get() as $row) {
            $confirmed[self::key($row->workflow_case_node_id, $row->workflow_case_history_id, $row->user_id)] = true;
        }

        $states = [];

        foreach ($targets as $target) {
            $node = $nodes->get($target->workflow_case_node_id)
                ?? throw new LogicException("確認対象者の行のノード {$target->workflow_case_node_id} に確認する人が無い");
            $isConfirmed = isset($confirmed[self::key($node->id, $node->workflow_case_history_id, $target->user_id)]);
            $states[$node->id][$target->user_id] = self::stateOf($case, $node, $isConfirmed);
        }

        return $states;
    }

    /**
     * 1 人の確認の状態(設計/ワークフロー/案件の運用/案件の操作/README.md の 1.5 節の式)。
     *
     * @param  bool  $confirmed  その人の確認の行のうち、ノードの今の履歴の行を指すものがあるか
     */
    public static function stateOf(
        WorkflowCase $case,
        WorkflowCaseNode $node,
        bool $confirmed,
    ): WorkflowConfirmationProgress {
        $done = $node->state === WorkflowNodeState::Done;

        return match (true) {
            $node->state === WorkflowNodeState::Skipped => WorkflowConfirmationProgress::Skipped,
            $case->state === WorkflowCaseState::Completed && ! $done => WorkflowConfirmationProgress::NotProcessed,
            $done && $confirmed => WorkflowConfirmationProgress::Confirmed,
            $done => WorkflowConfirmationProgress::Unconfirmed,
            $node->kind === WorkflowNodeKind::Apply => WorkflowConfirmationProgress::Reapply,
            default => WorkflowConfirmationProgress::Waiting,
        };
    }

    private static function key(int $nodeId, ?int $historyId, int $userId): string
    {
        return "{$nodeId}:{$historyId}:{$userId}";
    }
}
