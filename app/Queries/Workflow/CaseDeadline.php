<?php

namespace App\Queries\Workflow;

/**
 * 案件ごとの経過日数・期限・期限超過({@see CaseDeadlines::summaryOf()} の結果。docs の 設計/ワークフロー/案件の運用/案件の操作/README.md の 1.4 節)。
 */
final readonly class CaseDeadline
{
    /**
     * @param  int|null  $elapsedDays  渡したノードの集まりで最も長い経過日数(暦日の数)。null = 「—」(集まりが空)
     * @param  string|null  $dueOn  最も早い期限日(Y-m-d)。null = 「—」(集まりが空か、どのノードも期限の日数を持たない)
     * @param  bool  $isOverdue  どれかのノードが期限超過
     */
    public function __construct(
        public ?int $elapsedDays,
        public ?string $dueOn,
        public bool $isOverdue,
    ) {}
}
