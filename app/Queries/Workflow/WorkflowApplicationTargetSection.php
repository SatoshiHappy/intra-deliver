<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordShowSections;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * 業務レコードの詳細のサイドパネルの節「このレコードを申請対象にする申請」(docs の
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.4 節、形 =
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/節の口.md の 1 節の table)。どのコンテンツの申請対象の参照先でもない業務モジュールでは出さない。
 * レコードの閲覧の判定は呼び手(Records)が済ませてから呼び、行は案件の閲覧の判定 {@see CaseVisibility} で絞る(件数も同じ絞り)。
 *
 * @phpstan-import-type RecordShowTableSection from RecordShowSections
 * @phpstan-import-type CaseRow from CaseRows
 */
class WorkflowApplicationTargetSection implements RecordShowSections
{
    /** 表の列。共通の描画は最初の列にリンクを張るので、リンクの申請名(9.4 節)を先に置く */
    private const COLUMNS = [
        ['code' => 'name', 'label' => '申請名', 'type' => 'title'],
        ['code' => 'number', 'label' => '申請番号', 'type' => 'text'],
        ['code' => 'flow', 'label' => 'フロー', 'type' => 'text'],
        ['code' => 'applicant', 'label' => '申請者', 'type' => 'text'],
        ['code' => 'applied-on', 'label' => '申請日', 'type' => 'date'],
        ['code' => 'state', 'label' => '状態', 'type' => 'status'],
    ];

    private const ACTIVE_FALLBACK = ['承認待ち', 'pending'];

    public function __construct(
        private ApplicationTargetCases $applicationTargetCases,
        private CaseVisibility $visibility,
        private CaseRows $rows,
    ) {}

    public function descriptor(): array
    {
        return ['code' => 'workflow-application-targets', 'heading' => 'このレコードを申請対象にする申請', 'shortHeading' => '申請'];
    }

    /**
     * 件数 = 閲覧できる行の数(0 件でも出す)。
     */
    public function presence(AccessContext $context, string $moduleCode, int $recordId): ?array
    {
        $cases = $this->cases($context, $moduleCode, $recordId);

        return $cases === null ? null : ['count' => $cases->count()];
    }

    /**
     * 行の並び = 申請日時の新しい順(同じなら id の降順)。
     *
     * @return RecordShowTableSection|null
     */
    public function section(AccessContext $context, string $moduleCode, int $recordId): ?array
    {
        $query = $this->cases($context, $moduleCode, $recordId);

        if ($query === null) {
            return null;
        }

        $cases = $query->orderByDesc('applied_at')->orderByDesc('id')->get();
        $rows = $this->rows->rows(Requester::fromAccessContext($context), $context->date, $context->localeCode, $cases);

        return [
            'kind' => 'table',
            'count' => count($rows),
            'columns' => self::COLUMNS,
            'rows' => array_map(self::row(...), $rows),
            'emptyText' => 'このレコードを申請対象にする申請はありません。',
            'more' => null,
        ];
    }

    /**
     * レコードを申請対象に持つ、閲覧できる一時保存でない案件の問い合わせ。業務モジュールがどのコンテンツの申請対象の参照先でもなければ null。
     *
     * @return Builder<WorkflowCase>|null
     */
    private function cases(AccessContext $context, string $moduleCode, int $recordId): ?Builder
    {
        $fieldIds = $this->applicationTargetCases->fieldIdsByModule()[$moduleCode] ?? [];

        if ($fieldIds === []) {
            return null;
        }

        $query = WorkflowCase::query()
            ->whereIn('record_id', $this->applicationTargetCases->applicationRecordIdsOf($recordId, $fieldIds))
            ->where('state', '<>', WorkflowCaseState::Draft);

        return $this->visibility->visible($query, Requester::fromAccessContext($context), $context->date);
    }

    /**
     * 一覧の行から表の行を作る(申請日 = 申請日時の Asia/Tokyo の暦の日付。申請名のリンク = 申請の詳細)。
     *
     * @param  CaseRow  $row
     * @return RecordShowTableSection['rows'][int]
     */
    private static function row(array $row): array
    {
        $appliedAt = $row['appliedAt'];
        $appliedOn = $appliedAt === null
            ? '' : CarbonImmutable::parse($appliedAt)->setTimezone(AccessContext::TIMEZONE)->toDateString();
        [$stateText, $tone] = self::badgeOf($row);

        return [
            'code' => (string) $row['id'],
            'url' => route('workflow.cases.show', $row['id']),
            'cells' => [
                ['text' => $row['name'], 'tone' => null],
                ['text' => $row['number'] ?? '', 'tone' => null],
                ['text' => $row['flowName'], 'tone' => null],
                ['text' => $row['applicant']['name'] ?? $row['applicant']['code'], 'tone' => null],
                ['text' => $appliedOn, 'tone' => null],
                ['text' => $stateText, 'tone' => $tone],
            ],
        ];
    }

    /**
     * 状態の札(完了は完了の理由、未完了は現在のノードの状態から引く。完了の理由が当たらないのはデータの不整合なので握らない)。
     *
     * @param  CaseRow  $row
     * @return array{string, 'pending'|'rejected'|'returned'|'settled'}
     */
    private static function badgeOf(array $row): array
    {
        if ($row['state'] === WorkflowCaseState::Completed->value) {
            $reason = WorkflowCompletionReason::tryFrom((string) ($row['completionReason'] ?? ''))
                ?? throw new LogicException("完了した案件 {$row['id']} が完了の理由を持たない");

            // 札の語と色 = docs の 設計/ワークフロー/画面/ホームと意匠とE2E.md の 8 節。理由を足したら match が漏れを止める
            return match ($reason) {
                WorkflowCompletionReason::Approved => ['完了(承認)', 'settled'],
                WorkflowCompletionReason::ApprovedEarly => ['完了(承認終了)', 'settled'],
                WorkflowCompletionReason::Rejected => ['完了(否認)', 'rejected'],
                WorkflowCompletionReason::Withdrawn => ['完了(取止め)', 'settled'],
            };
        }

        // 未完了で札の語に当たらないものは承認待ち(画面の案件の状態の札と同じ = docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節)
        return WorkflowHomeLabels::CURRENT_NODE_STATES[$row['currentSummary']['state'] ?? ''] ?? self::ACTIVE_FALLBACK;
    }
}
