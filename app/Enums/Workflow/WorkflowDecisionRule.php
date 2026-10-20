<?php

namespace App\Enums\Workflow;

use LogicException;

/**
 * 縦配置の可決の条件(表 workflow_route_nodes・workflow_case_nodes の decision_rule。縦配置のノードだけが持つ)。docs の 設計/ワークフロー/ルートの深さ/データモデル/README.md 1 節。値は DB の検査制約と同じ一覧。
 */
enum WorkflowDecisionRule: string
{
    /** 全員 */
    case All = 'all';

    /** 人数(decision_value = 必要な人数) */
    case Count = 'count';

    /** 割合(decision_value = 必要な割合 %) */
    case Ratio = 'ratio';

    /**
     * 必要な数 k(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.5。割合は整数で切り上げる)。
     *
     * @param  int|null  $value  可決の条件の値(人数 = K、割合 = p。全員は null)
     * @param  int  $assignees  人数 n(今の指定の人数)
     *
     * @throws LogicException 人数・割合の値が無いとき(DB の検査制約が止める)
     */
    public function required(?int $value, int $assignees): int
    {
        return match ($this) {
            self::All => $assignees,
            self::Count => $value ?? throw new LogicException('人数の可決の条件に値が無い'),
            self::Ratio => intdiv($assignees * ($value ?? throw new LogicException('割合の可決の条件に値が無い')) + 99, 100),
        };
    }

    /**
     * 合議の判定の 1 か所(docs の 設計/ワークフロー/ルートの深さ/案件の操作/README.md 2.5)。業務処理の合議の判定と詳細の合議の進み具合が同じ数え方を使う。
     * 番号が今の指定の人数 n 以下の子だけを数え、承認 a ≥ k → 可決、n − 否認 r < k → 可決できなくなった、どちらでもなければ未決(可決を先に見る)。DB を読まない。
     *
     * @param  int|null  $value  可決の条件の値(人数 = K、割合 = p。全員は null)
     * @param  int  $assignees  人数 n(今の指定の人数)
     * @param  array<int, WorkflowAction|null>  $actions  子の番号 → 処理の種別(処理済みでなければ null)。全部の子
     * @return array{approvals: int, rejections: int, required: int, outcome: WorkflowCouncilOutcome}
     *
     * @throws LogicException 人数・割合の値が無いとき
     */
    public function decide(?int $value, int $assignees, array $actions): array
    {
        $counted = array_filter($actions, fn (int $sequence) => $sequence <= $assignees, ARRAY_FILTER_USE_KEY);
        $approvals = count(array_filter($counted, fn (?WorkflowAction $action) => in_array($action, WorkflowAction::countsAsApproval(), true)));
        $rejections = count(array_filter($counted, fn (?WorkflowAction $action) => $action === WorkflowAction::Reject));
        $required = $this->required($value, $assignees);

        return [
            'approvals' => $approvals,
            'rejections' => $rejections,
            'required' => $required,
            'outcome' => match (true) {
                $approvals >= $required => WorkflowCouncilOutcome::Passed,
                $assignees - $rejections < $required => WorkflowCouncilOutcome::Failed,
                default => WorkflowCouncilOutcome::Pending,
            },
        ];
    }
}
