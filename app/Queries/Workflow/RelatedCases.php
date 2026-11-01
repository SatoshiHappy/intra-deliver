<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordViewAccess;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * つながる申請(docs の 設計/ワークフロー/業務データとの接続/写しとつながる申請.md 6 節、設計/ワークフロー/申請の周辺/申請の内容の閲覧.md の 2 節、
 * 設計/ワークフロー/画面/申請と案件.md 5 節の related)。起点と群は {@see RelatedCaseOrigins}・{@see RelatedCaseGroups} が作り、ここで候補の案件に
 * 閲覧の判定 {@see CaseVisibility} を掛けて(見えない案件は名前も件数も返さない)群に置く。呼び手(詳細)がこの案件の閲覧の判定を済ませてから呼ぶ。
 *
 * @phpstan-import-type CaseRow from CaseRows
 * @phpstan-import-type RelatedOrigin from RelatedCaseOrigins
 * @phpstan-import-type RelatedGroupSource from RelatedCaseGroups
 *
 * @phpstan-type RelatedGroup array{heading: string, cases: list<CaseRow>}
 */
class RelatedCases
{
    public function __construct(
        private RelatedCaseOrigins $origins,
        private RelatedCaseGroups $groups,
        private RecordViewAccess $viewAccess,
        private ApplicationTargetValues $applicationTargetValues,
        private CaseVisibility $visibility,
        private CaseRows $rows,
    ) {}

    /**
     * 群の並び(空の群は入れない)。recordAccess(申請書のレコードを閲覧できるか = 申請の詳細の recordAccess)が偽なら申請書のレコードからはたどらない。
     * 申請書の値は、recordAccess が真なら今の値、偽なら最後の申請の写しの値(申請対象の項目を読むため。申請書は申請の後に変わらないので同じ値)。
     *
     * @param  array<string, mixed>  $values  申請書のレコードの値(項目のコード → 画面の値)
     * @return list<RelatedGroup>
     */
    public function of(
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
        WorkflowCase $case,
        bool $recordAccess,
        array $values,
    ): array {
        $recordId = $case->record_id ?? throw new LogicException("申請した案件 {$case->id} がレコードを指していない");
        $modules = RecordModule::query()->whereNull('parent_record_module_id')->get(['id', 'code', 'name']);
        $moduleCodes = array_map(strval(...), $modules->pluck('code', 'id')->all());
        $moduleNames = array_map(strval(...), $modules->pluck('name', 'code')->all());
        $application = $recordAccess ? $this->applicationOrigin($recordId, $moduleCodes, $moduleNames) : null;
        $applicationTargetOrigin = $this->applicationTargetOrigin(
            $requester, $date, $case, $values, $moduleCodes, $moduleNames,
        );
        $groups = $this->groups->of($requester, $date, $applicationTargetOrigin, $application);

        return $this->placed($requester, $date, $localeCode, $case, $groups);
    }

    /**
     * 申請書のレコードの起点(呼び手が recordAccess を確かめてから呼ぶ)。
     *
     * @param  array<int, string>  $moduleCodes  業務モジュールの id → コード
     * @param  array<string, string>  $moduleNames  業務モジュールのコード → 名前
     * @return RelatedOrigin
     */
    private function applicationOrigin(int $recordId, array $moduleCodes, array $moduleNames): array
    {
        $moduleId = Record::query()->whereKey($recordId)->value('record_module_id');
        $moduleCode = $moduleCodes[$moduleId] ?? throw new LogicException("申請書のレコード {$recordId} の業務モジュールが無い");

        return $this->origins->of($recordId, $moduleCode, $moduleNames[$moduleCode], $moduleCodes);
    }

    /**
     * 申請対象の起点(コンテンツに申請対象の項目が無い・値が空・見る人が閲覧できなければ null)。
     *
     * @param  array<string, mixed>  $values  申請書のレコードの値
     * @param  array<int, string>  $moduleCodes  業務モジュールの id → コード
     * @param  array<string, string>  $moduleNames  業務モジュールのコード → 名前
     * @return RelatedOrigin|null
     */
    private function applicationTargetOrigin(
        Requester $requester,
        DateTimeInterface $date,
        WorkflowCase $case,
        array $values,
        array $moduleCodes,
        array $moduleNames,
    ): ?array {
        $applicationTarget = $this->applicationTargetValues->applicationTargetOf(CaseRows::flowOf($case));
        if ($applicationTarget === null) {
            return null;
        }

        $applicationTargetId = $this->applicationTargetValues->applicationTargetIdOf($applicationTarget, $values);

        if ($applicationTargetId === null) {
            return null;
        }

        $moduleCode = $applicationTarget['module'];
        $moduleName = $moduleNames[$moduleCode]
            ?? throw new LogicException("申請対象の業務モジュール {$moduleCode} が親の無い業務モジュールに無い");

        return $this->viewAccess->canView($requester, $moduleCode, $applicationTargetId, $date)
            ? $this->origins->of($applicationTargetId, $moduleCode, $moduleName, $moduleCodes)
            : null;
    }

    /**
     * 群に、閲覧できる一時保存でない案件(この案件を除く)を申請日時の新しい順に置く。同じ案件は先の群だけに置き、案件の無い群は返さない。
     *
     * @param  list<RelatedGroupSource>  $groups
     * @return list<RelatedGroup>
     */
    private function placed(
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
        WorkflowCase $case,
        array $groups,
    ): array {
        $recordIds = array_values(array_unique(array_merge([], ...array_column($groups, 'recordIds'))));

        if ($recordIds === []) {
            return [];
        }

        $candidates = $this->candidates($requester, $date, $case, $recordIds);
        $rows = array_column($this->rows->rows($requester, $date, $localeCode, $candidates), null, 'id');
        $result = [];
        $placed = [];

        foreach ($groups as $group) {
            $members = $candidates->filter(fn (WorkflowCase $candidate) => ! isset($placed[$candidate->id])
                && in_array($candidate->record_id, $group['recordIds'], true));

            if ($members->isNotEmpty()) {
                $cases = array_values($members->map(fn (WorkflowCase $member) => $rows[$member->id])->all());
                $result[] = ['heading' => $group['heading'], 'cases' => $cases];
                $placed += array_fill_keys($members->modelKeys(), true);
            }
        }

        return $result;
    }

    /**
     * 申請書のレコードを指す、閲覧できる一時保存でない案件(この案件を除く。申請日時の新しい順、同じなら id の降順)。
     *
     * @param  list<int>  $recordIds
     * @return Collection<int, WorkflowCase>
     */
    private function candidates(
        Requester $requester,
        DateTimeInterface $date,
        WorkflowCase $case,
        array $recordIds,
    ): Collection {
        $query = WorkflowCase::query()->whereIn('record_id', $recordIds)->whereKeyNot($case->id)
            ->where('state', '<>', WorkflowCaseState::Draft);

        return $this->visibility->visible($query, $requester, $date)
            ->orderByDesc('applied_at')->orderByDesc('id')->get();
    }
}
