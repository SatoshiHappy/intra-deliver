<?php

namespace Tests\Support;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;

/**
 * 状態 × 操作の表のテスト({@see ChecksCaseOperationTable})の、案件を人の記号で書き表す部分(ノードの形と最新の履歴の行。docs の
 * 設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 14.1 節)。人の記号は使う側の tablePeople()・person() で引く。
 */
trait DescribesTableCases
{
    /**
     * 最新の履歴の行の [種別, 処理者, 実行者(null = 処理者本人), 履歴の人を並べた記号]。
     *
     * @return array{string, string, string|null, string}
     */
    private function latestRowLetters(WorkflowCase $case): array
    {
        $letterOf = fn (?int $userId) => $userId === null ? null : $this->letterOf($userId);
        $row = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->latest('id')->firstOrFail();
        $people = WorkflowCaseHistoryUser::query()->where('workflow_case_history_id', $row->id)
            ->orderBy('sequence')->pluck('user_id')->map($letterOf)->all();

        $executor = $letterOf($row->executor_user_id);

        return [$row->action->value, (string) $letterOf($row->user_id), $executor, implode('', $people)];
    }

    /**
     * ユーザーの id の人の記号(表の人でなければ ?)。
     */
    private function letterOf(int $userId): string
    {
        foreach (array_keys($this->tablePeople()) as $letter) {
            if ($this->person($letter)?->id === $userId) {
                return $letter;
            }
        }

        return '?';
    }

    private function sortedLetters(string $letters): string
    {
        $chars = str_split($letters);
        sort($chars);

        return implode('', $chars);
    }

    /**
     * ノードの形を記号で: 処理済み = done/<種別>、現在のノード = <状態>:<処理対象者の記号>、到達していない = unreached。
     *
     * @return list<string>
     */
    private function compactNodes(WorkflowCase $case): array
    {
        $letters = [];

        foreach (array_keys($this->tablePeople()) as $letter) {
            $user = $this->person($letter);

            if ($user !== null) {
                $letters[$user->id] = $letter;
            }
        }

        return array_map(fn (array $node) => match ($node[1]) {
            'done' => "done/{$node[2]}",
            'unreached' => 'unreached',
            default => $node[1].':'.implode('', array_map(fn (int $userId) => $letters[$userId] ?? '?', $node[3])),
        }, $this->nodeStates($case));
    }
}
