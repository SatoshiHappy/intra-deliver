<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/**
 * 案件の詳細の履歴(docs の 設計/ワークフロー/画面/申請と案件.md 5 節の histories、業務データとの接続/写しとつながる申請.md 5 節の差分)。並びは id の昇順(画面が並べ替える)。
 * 差分は再申請の行だけで、直前の申請・再申請の行(id が小さい側で最大)が指す版を前、その行が指す版を後として業務データの入れ物の口
 * {@see RecordRevisionQuery::diffOnCurrentDefinition()}(業務モジュールの read を見ない)で求め、画面の差分の形(項目と明細)にする
 * (設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節)。呼び手(詳細)が案件の閲覧の判定を済ませてから呼ぶ。
 *
 * @phpstan-import-type Person from WorkflowPeople
 * @phpstan-import-type RowChange from RecordRevisionQuery
 *
 * 承認の行の指定(ルートの深さ/画面/案件の側の画面.md 7 節の履歴「指定: {指定されたステップ} = {名前}」)= 承認したノードが指定するノードごと(写した並び)に、その行が書いた指定の行
 * (人は指定の順)。行が 0 のノードは people が空(= 0 人を指定 = 省略。データモデル/案件の表.md 3.5、通しの回の T08)。ノード名は表示名(ルートの深さ/案件の操作/README.md 1.3)。
 *
 * @phpstan-type CaseDiff array{fields: list<array{code: string, before: mixed, after: mixed}>,
 *     details: list<array{code: string, rows: list<RowChange>}>}
 *
 * 処理実行者の表示 = actor(処理者)と executor(代理で処理したときの実行者。本人なら null)。添える 1 行の人 users =
 * 振替先・処理対象者の変更と再展開の後の人(履歴の人)、指定の変更の人(その行の指定の行)。
 * sendBackNodeName = 差戻しの行の差戻し先(戻るの行は null)。moveTargetName = ステップの移動(戻る)の
 * 戻り先(設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節、データモデル/表.md の 3.9 節)。
 * @phpstan-type History array{id: int, nodeName: string, action: string, actor: Person, executor: Person|null,
 *     at: string|null, comment: string|null, sendBackNodeName: string|null, diff: CaseDiff|null,
 *     diffBaseAt: string|null, assignments: list<array{nodeName: string, people: list<Person>}>, users: list<Person>,
 *     moveTargetName: string|null}
 */
class CaseHistories
{
    /** 更新履歴の版を指す(提出の)種別 */
    public const SUBMISSIONS = [WorkflowAction::Apply, WorkflowAction::Reapply];

    /** 履歴の人を持つ種別(データモデル/表.md の 3.9 節) */
    private const PEOPLE_ACTIONS = [WorkflowAction::Transfer, WorkflowAction::Retarget, WorkflowAction::Reexpand];

    public function __construct(
        private RecordRevisionQuery $revisions,
        private WorkflowPeople $people,
    ) {}

    /**
     * @param  Collection<int, WorkflowCaseHistory>  $histories  案件の履歴(id の昇順)
     * @param  Collection<int, WorkflowCaseNode>  $nodes  案件のノード(id → ノード)
     * @param  array<int, Person>  $people  処理者と実行者を含むユーザーの表示
     * @return list<History>
     */
    public function of(
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
        Collection $histories,
        Collection $nodes,
        array $people,
    ): array {
        $rows = [];
        $base = null;
        $assignments = $this->assignmentsOf($histories, $nodes, $date, $localeCode);
        $users = $this->usersOf($histories, $date, $localeCode);

        foreach ($histories as $history) {
            $isReapply = $history->action === WorkflowAction::Reapply && $base !== null;
            $executorId = $history->executor_user_id;
            $rows[] = [
                'id' => $history->id,
                'nodeName' => $this->nodeName($nodes, $history->workflow_case_node_id),
                'action' => $history->action->value,
                'actor' => $people[$history->user_id],
                'executor' => $executorId === null ? null : $people[$executorId],
                'at' => $history->created_at?->toIso8601String(),
                'comment' => $history->comment,
                'sendBackNodeName' => $this->targetName($nodes, $history, WorkflowAction::SendBack),
                'diff' => $isReapply ? $this->diff($requester, $base, $history, $date, $localeCode) : null,
                'diffBaseAt' => $isReapply ? $base->created_at?->toIso8601String() : null,
                'assignments' => $assignments[$history->id] ?? [],
                'users' => $users[$history->id] ?? [],
                'moveTargetName' => $this->targetName($nodes, $history, WorkflowAction::MoveBack),
            ];

            if (in_array($history->action, self::SUBMISSIONS, true)) {
                $base = $history;
            }
        }

        return $rows;
    }

    /**
     * 再申請の行の差分を、画面の差分の形(項目のコード・前・後と、明細の項目のコード・行の変化)にする(項目と明細の描き方を変えない)。
     *
     * @return CaseDiff
     *
     * @throws LogicException 申請・再申請の行が版を指していないとき(検査制約が止める)
     */
    private function diff(
        Requester $requester,
        WorkflowCaseHistory $base,
        WorkflowCaseHistory $history,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $before = $this->revisionOf($base);
        $after = $this->revisionOf($history);
        $diff = $this->revisions->diffOnCurrentDefinition($requester, $before, $after, $date, $localeCode);
        $field = fn (array $field) => [
            'code' => $field['code'], 'before' => $field['before'], 'after' => $field['after'],
        ];
        $detail = fn (array $detail) => ['code' => $detail['code'], 'rows' => $detail['rows']];

        return ['fields' => array_map($field, $diff['fields']), 'details' => array_map($detail, $diff['details'])];
    }

    /**
     * @throws LogicException 版を指していないとき
     */
    private function revisionOf(WorkflowCaseHistory $history): int
    {
        return $history->record_revision_id ?? throw new LogicException("提出の履歴の行 {$history->id} が版を指していない");
    }

    /**
     * @param  Collection<int, WorkflowCaseNode>  $nodes
     */
    private function nodeName(Collection $nodes, int $nodeId): string
    {
        return $nodes->get($nodeId)?->displayName() ?? throw new LogicException("案件のノード {$nodeId} を読み込んでいない");
    }

    /**
     * 差戻しの行の差戻し先と戻るの行の戻り先の表示名(2 つは同じ列 = データモデル/表.md の 3.7 節。指定した種別の行だけが値を持ち、ほかの行は null)。
     *
     * @param  Collection<int, WorkflowCaseNode>  $nodes
     */
    private function targetName(Collection $nodes, WorkflowCaseHistory $history, WorkflowAction $action): ?string
    {
        $targetId = $history->send_back_workflow_case_node_id;

        if ($history->action !== $action || $targetId === null) {
            return null;
        }

        return $this->nodeName($nodes, $targetId);
    }

    /**
     * 履歴の行ごとの添える 1 行の人(行の id → 並びの順の人)。振替・処理対象者の変更・再展開は履歴の人、指定の変更はその行の指定の行。
     * 問い合わせは行の数に依らない(履歴の人・指定の行・名前が各 1 回まで)。
     *
     * @param  Collection<int, WorkflowCaseHistory>  $histories
     * @return array<int, list<Person>>
     */
    private function usersOf(Collection $histories, DateTimeInterface $date, string $localeCode): array
    {
        $idsOf = fn (array $actions) => array_values($histories
            ->filter(fn (WorkflowCaseHistory $history) => in_array($history->action, $actions, true))
            ->map(fn (WorkflowCaseHistory $history) => $history->id)->all());
        $pairs = [
            ...self::userPairs(WorkflowCaseHistoryUser::query(), $idsOf(self::PEOPLE_ACTIONS)),
            ...self::userPairs(WorkflowCaseAssignment::query(), $idsOf([WorkflowAction::Reassign])),
        ];

        if ($pairs === []) {
            return [];
        }

        $people = $this->people->users(array_column($pairs, 1), $date, $localeCode);
        $byHistory = [];

        foreach ($pairs as [$historyId, $userId]) {
            $byHistory[$historyId][] = $people[$userId];
        }

        return $byHistory;
    }

    /**
     * 履歴の行を指す人の行の [行の id, ユーザーの id](並びの順)。行が無ければ問い合わせない。
     *
     * @param  Builder<WorkflowCaseHistoryUser>|Builder<WorkflowCaseAssignment>  $query
     * @param  list<int>  $historyIds
     * @return list<array{int, int}>
     */
    private static function userPairs(Builder $query, array $historyIds): array
    {
        if ($historyIds === []) {
            return [];
        }

        $rows = $query->whereIn('workflow_case_history_id', $historyIds)->orderBy('sequence')->get();
        $pair = fn (WorkflowCaseHistoryUser|WorkflowCaseAssignment $row) => [
            (int) $row->workflow_case_history_id, (int) $row->user_id,
        ];

        return array_values($rows->map($pair)->all());
    }

    /**
     * 履歴の行ごとの指定(行の id → 指定されたノードごとの人)。承認の行のうち、そのノードが指定するノードを持つものだけ。
     *
     * @param  Collection<int, WorkflowCaseHistory>  $histories
     * @param  Collection<int, WorkflowCaseNode>  $nodes
     * @return array<int, list<array{nodeName: string, people: list<Person>}>>
     */
    private function assignmentsOf(Collection $histories, Collection $nodes, DateTimeInterface $date, string $localeCode): array
    {
        $designated = $nodes->filter(fn (WorkflowCaseNode $node) => $node->designator_workflow_case_node_id !== null)->sort(WorkflowCaseNode::compareInRoute(...))->groupBy('designator_workflow_case_node_id');
        $approvals = $histories->filter(fn (WorkflowCaseHistory $history) => $history->action === WorkflowAction::Approve && $designated->has($history->workflow_case_node_id));

        if ($approvals->isEmpty()) {
            return [];
        }

        $rows = WorkflowCaseAssignment::query()->whereIn('workflow_case_history_id', array_values($approvals->map(fn (WorkflowCaseHistory $history) => $history->id)->all()))->orderBy('sequence')->get();
        $people = $this->people->users(array_values($rows->map(fn (WorkflowCaseAssignment $row) => (int) $row->user_id)->all()), $date, $localeCode);
        $byHistoryAndNode = $rows->groupBy(fn (WorkflowCaseAssignment $row) => "{$row->workflow_case_history_id}:{$row->workflow_case_node_id}");
        $result = [];

        foreach ($approvals as $history) {
            foreach ($designated->get($history->workflow_case_node_id) ?? [] as $node) {
                $result[$history->id][] = [
                    'nodeName' => $node->displayName(),
                    'people' => array_values(($byHistoryAndNode->get("{$history->id}:{$node->id}") ?? new Collection)->map(fn (WorkflowCaseAssignment $row) => $people[(int) $row->user_id])->all()),
                ];
            }
        }

        return $result;
    }
}
