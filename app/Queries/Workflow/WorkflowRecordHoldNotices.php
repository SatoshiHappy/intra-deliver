<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordHoldLink;
use App\Queries\Records\Api\RecordHoldNotices;
use App\Queries\Records\Api\RecordHoldState;
use DateTimeInterface;
use LogicException;

/**
 * 持ち主の案内のワークフローの実装(規則 = docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md の 4 節の「持ち主の案内の実装」、
 * 口の形 = 公開の口/表示と関係の口.md の 7.3)。起動(App\Providers\AppServiceProvider)がタグ {@see RecordHoldNotices::TAG} で登録する。
 * 申請の詳細を開けない人に申請番号を見せないため、{@see self::stateOf()} は入口と同じ絞り込み({@see CaseVisibility::appliedCase()})を通る。
 * 表示だけの口なので、案件の表を鍵なしで読み、書き込みの判定({@see WorkflowRecordHolds})からは呼ばない(口の約束 = 7.3 の行)。
 */
class WorkflowRecordHoldNotices implements RecordHoldNotices
{
    public function __construct(
        private CaseVisibility $visibility,
        private CurrentNodes $currentNodes,
    ) {}

    /**
     * null を返す場合 = 申請と書き込み.md の 4 節の stateOf。閲覧できないのは失敗でないので報告しない(口の約束)。
     */
    public function stateOf(Requester $requester, string $holder, DateTimeInterface $date): ?RecordHoldState
    {
        $caseId = WorkflowRecordHolds::caseIdOf($holder);

        if ($caseId === null) {
            return null;
        }

        $case = $this->visibility->appliedCase($requester, $date, $caseId)->first();

        if ($case === null) {
            return null;
        }

        $nodes = $case->state === WorkflowCaseState::Active ? $this->currentNodes->of([$case->id])[$case->id] : [];
        $labelState = CurrentNodes::labelStateOf($nodes);
        [$status, $tone] = CaseStateLabels::of($case->id, $case->state, $case->completion_reason, $labelState);
        $link = self::linkOf($case->id, $case->case_number);

        return new RecordHoldState($link, $status, $tone, CurrentNodes::namesWithNodeHoldOf($nodes));
    }

    /**
     * 版から案件へは履歴の行の record_revision_id で引く(版は案件を指さない = ADR 0047 の背景)。1 回の問い合わせ。
     */
    public function revisionLinks(int $recordId, array $revisionIds): array
    {
        if ($revisionIds === []) {
            return [];
        }

        $rows = WorkflowCaseHistory::query()
            ->join('workflow_cases', 'workflow_cases.id', '=', 'workflow_case_histories.workflow_case_id')
            ->where('workflow_cases.record_id', $recordId)
            ->whereIn('workflow_case_histories.record_revision_id', $revisionIds)
            ->orderBy('workflow_case_histories.id')
            ->toBase()
            ->get([
                'workflow_case_histories.record_revision_id',
                'workflow_cases.id as case_id',
                'workflow_cases.case_number',
            ]);
        $links = [];

        foreach ($rows as $row) {
            $links[(int) $row->record_revision_id] ??= self::linkOf(
                (int) $row->case_id,
                $row->case_number === null ? null : (int) $row->case_number,
            );
        }

        return $links;
    }

    /**
     * 業務モジュールの行数で問い合わせが増えないよう、レコードの表と結合して 1 回で数える。
     */
    public function attachmentDisableNotice(int $recordModuleId): ?string
    {
        $count = WorkflowCase::query()
            ->join('records', 'records.id', '=', 'workflow_cases.record_id')
            ->where('workflow_cases.state', WorkflowCaseState::Active)
            ->where('workflow_cases.is_attachment_enabled', true)
            ->where('records.record_module_id', $recordModuleId)
            ->count();

        return $count === 0
            ? null
            : "添付ファイルを追加・削除できる進行中の申請が {$count} 件あります。解除すると、これらの申請の再申請と処理でも添付ファイルを追加・削除できなくなります。";
    }

    /**
     * 申請番号(リンクの語)と申請の詳細の URL。
     *
     * @throws LogicException 申請した案件が案件番号を持たないとき(データの不整合。束ねる 1 か所が報告して案内の無い形にする)
     */
    private static function linkOf(int $caseId, ?int $caseNumber): RecordHoldLink
    {
        return new RecordHoldLink(
            CaseNumbers::format($caseNumber ?? throw new LogicException("申請した案件 {$caseId} が案件番号を持たない")),
            route('workflow.cases.show', ['case' => $caseId]),
        );
    }
}
