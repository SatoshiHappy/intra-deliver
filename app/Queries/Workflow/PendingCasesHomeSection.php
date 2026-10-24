<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseRowField;
use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\Api\HomeSections;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * ホームの未処理の申請の表(docs の 設計/ワークフロー/画面/ホームと意匠とE2E.md 7 節。口 = {@see HomeSections}、形 = 設計/メニュー/ホーム.md 6.2)。未処理の先頭の 5 行
 * (並び = 案件一覧と同じ)。列 = 申請日(申請日時の Asia/Tokyo の暦の日付)・申請名(詳細へ)・申請者(名前、無ければコード)・状態(案件の状態の札)・
 * 期限(設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節)。申請名・申請者・現在のノード・期限は一覧の未処理の行({@see CaseRows})から作る
 * (一覧と同じ読み取り)。ゲストには null、0 行でも出す(0 件の文)。
 *
 * @phpstan-import-type HomeTableSection from HomeSections
 * @phpstan-import-type CaseRow from CaseRows
 * @phpstan-import-type Deadline from CaseRowExtras
 */
class PendingCasesHomeSection implements HomeSections
{
    /** 出す行の数 */
    public const LIMIT = 5;

    public function __construct(
        private WorkflowHomeQuery $home,
        private CaseRows $rows,
    ) {}

    /**
     * @return HomeTableSection|null
     */
    public function section(AccessContext $context): ?array
    {
        if ($this->home->isGuest($context)) {
            return null;
        }

        $cases = $this->home->pending($context)->limit(self::LIMIT)->get();
        $requester = Requester::fromAccessContext($context);
        $fields = [WorkflowCaseRowField::Deadline];
        $rows = $this->rows->rows($requester, $context->date, $context->localeCode, $cases, fields: $fields);

        return [
            'code' => 'workflow-pending',
            'kind' => 'table',
            'area' => 'main',
            'heading' => '未処理の申請',
            'columns' => [
                ['code' => 'applied-on', 'label' => '申請日', 'type' => 'date'],
                ['code' => 'name', 'label' => '申請名', 'type' => 'title'],
                ['code' => 'applicant', 'label' => '申請者', 'type' => 'text'],
                ['code' => 'state', 'label' => '状態', 'type' => 'status'],
                ['code' => 'due', 'label' => '期限', 'type' => 'status'],
            ],
            'rows' => array_map($this->row(...), $rows),
            'emptyText' => '処理を待っている申請はありません',
            'more' => ['label' => '申請一覧', 'url' => route('workflow.cases.index', ['tab' => 'pending'])],
        ];
    }

    /**
     * 一覧の行(申請名・申請者・現在のノードは一覧と同じ読み取り)から表の行を作る。申請日は申請日時の Asia/Tokyo の暦の日付。
     *
     * @param  CaseRow  $row
     * @return HomeTableSection['rows'][int]
     */
    private function row(array $row): array
    {
        $state = WorkflowHomeLabels::CURRENT_NODE_STATES[$row['currentSummary']['state'] ?? ''] ?? null;

        return [
            'code' => (string) $row['id'],
            'url' => route('workflow.cases.show', $row['id']),
            'cells' => [
                ['text' => $row['appliedAt'] === null ? '' : CarbonImmutable::parse($row['appliedAt'])->setTimezone(AccessContext::TIMEZONE)->toDateString(), 'tone' => null, 'mark' => null],
                ['text' => $row['name'], 'tone' => null, 'mark' => null],
                ['text' => $row['applicant']['name'] ?? $row['applicant']['code'], 'tone' => null, 'mark' => null],
                ['text' => $state[0] ?? '', 'tone' => $state[1] ?? null, 'mark' => null],
                self::dueCell($row['deadline']),
            ],
        ];
    }

    /**
     * 期限のセル(超過ならセル全体が 1 つの札 = 設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節)。
     *
     * @param  Deadline|null  $deadline
     * @return HomeTableSection['rows'][int]['cells'][int]
     */
    private static function dueCell(?array $deadline): array
    {
        $dueOn = $deadline['dueOn'] ?? null;

        return match (true) {
            $dueOn === null => ['text' => '—', 'tone' => null, 'mark' => null],
            $deadline['overdue'] ?? false => ['text' => "{$dueOn} 期限超過", 'tone' => 'rejected', 'mark' => null],
            default => ['text' => $dueOn, 'tone' => null, 'mark' => null],
        };
    }
}
