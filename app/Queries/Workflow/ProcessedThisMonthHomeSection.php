<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Menu\Api\HomeSections;
use App\Support\AccessContext;

/**
 * ホームの今月の処理済みの表(docs の 設計/ワークフロー/画面/ホームと意匠とE2E.md 7 節。口 = {@see HomeSections}、形 = 設計/メニュー/ホーム.md 6.2)。今月の処理済みの
 * 先頭の 5 行(1 行 = 履歴の 1 行。並び = 履歴の id の降順)。列 = 処理日時・申請名(詳細へ)・申請者(名前、無ければコード)・結果(8 節の履歴の処理の種別の札)。
 * ゲストには null、0 行でも出す(0 件の文)。
 *
 * @phpstan-import-type HomeTableSection from HomeSections
 */
class ProcessedThisMonthHomeSection implements HomeSections
{
    /** 出す行の数 */
    public const LIMIT = 5;

    public function __construct(
        private WorkflowHomeQuery $home,
        private WorkflowPeople $people,
    ) {}

    /**
     * @return HomeTableSection|null
     */
    public function section(AccessContext $context): ?array
    {
        if ($this->home->isGuest($context)) {
            return null;
        }

        $histories = $this->home->processedThisMonth($context)->with('workflowCase')->limit(self::LIMIT)->get();
        $applicants = $this->people->users(array_values($histories->map(fn (WorkflowCaseHistory $history) => (int) $history->workflowCase?->user_id)->all()), $context->date, $context->localeCode);

        return [
            'code' => 'workflow-processed-this-month',
            'kind' => 'table',
            'area' => 'main',
            'heading' => '今月の処理済み',
            'columns' => [
                ['code' => 'processed-at', 'label' => '処理日時', 'type' => 'datetime'],
                ['code' => 'name', 'label' => '申請名', 'type' => 'title'],
                ['code' => 'applicant', 'label' => '申請者', 'type' => 'text'],
                ['code' => 'result', 'label' => '結果', 'type' => 'status'],
            ],
            'rows' => array_values($histories->map(function (WorkflowCaseHistory $history) use ($applicants) {
                $applicant = $applicants[(int) $history->workflowCase?->user_id];
                $result = WorkflowHomeLabels::RESULTS[$history->action->value] ?? null;

                return [
                    'code' => (string) $history->id,
                    'url' => route('workflow.cases.show', $history->workflow_case_id),
                    'cells' => [
                        ['text' => (string) $history->created_at?->toIso8601String(), 'tone' => null, 'mark' => null],
                        ['text' => (string) $history->workflowCase?->name, 'tone' => null, 'mark' => null],
                        ['text' => $applicant['name'] ?? $applicant['code'], 'tone' => null, 'mark' => null],
                        ['text' => $result[0] ?? '', 'tone' => $result[1] ?? null, 'mark' => $result[2] ?? null],
                    ],
                ];
            })->all()),
            'emptyText' => '今月に処理した申請はありません',
            'more' => ['label' => '申請一覧', 'url' => route('workflow.cases.index', ['tab' => 'processed'])],
        ];
    }
}
