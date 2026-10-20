<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRelations;
use DateTimeInterface;

/**
 * 関連する案件(W-4。docs の 設計/ワークフロー/業務データとの接続/README.md 6 節、画面.md 5 節の related): 同じレコード → 参照先のレコード → 参照元のレコード
 * の 3 群。レコードの関係は業務データの入れ物の口 {@see RecordRelations}(主体の判定をしない)で求め、候補の案件に閲覧の判定 {@see CaseVisibility} を掛ける
 * (見えない案件は名前も件数も返さない)。一時保存とこの案件自身を除き、同じ案件は先の群だけに出す。群ごとに申請日時の新しい順(同じなら id の降順)。
 * 呼び手(詳細)がこの案件の閲覧の判定を済ませてから呼ぶ。
 *
 * @phpstan-import-type CaseRow from CaseRows
 */
class RelatedCases
{
    public function __construct(
        private RecordRelations $relations,
        private CaseVisibility $visibility,
        private CaseRows $rows,
    ) {}

    /**
     * @return array{sameRecord: list<CaseRow>, referenced: list<CaseRow>, referencing: list<CaseRow>}
     */
    public function of(Requester $requester, DateTimeInterface $date, string $localeCode, WorkflowCase $case, int $recordId): array
    {
        $related = $this->relations->relatedRecordIds($recordId);
        $groups = ['sameRecord' => [$recordId], 'referenced' => $related['referenced'], 'referencing' => $related['referencing']];
        $candidates = $this->visibility->visible(
            WorkflowCase::query()->whereIn('record_id', array_merge(...array_values($groups)))->whereKeyNot($case->id)->where('state', '<>', WorkflowCaseState::Draft),
            $requester,
            $date,
        )->orderByDesc('applied_at')->orderByDesc('id')->get();
        $rows = array_column($this->rows->rows($requester, $date, $localeCode, $candidates), null, 'id');
        $result = [];
        $placed = [];

        foreach ($groups as $group => $recordIds) {
            $members = $candidates->filter(fn (WorkflowCase $candidate) => in_array($candidate->record_id, $recordIds, true) && ! isset($placed[$candidate->id]));
            $result[$group] = array_values($members->map(fn (WorkflowCase $candidate) => $rows[$candidate->id])->all());
            $placed += array_fill_keys($members->modelKeys(), true);
        }

        return ['sameRecord' => $result['sameRecord'], 'referenced' => $result['referenced'], 'referencing' => $result['referencing']];
    }
}
