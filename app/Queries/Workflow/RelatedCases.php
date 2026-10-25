<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordViewAccess;
use App\Support\MixedValues;
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
     * 群の並び(空の群は入れない)。recordAccess(申請書のレコードを閲覧できるか)が偽なら申請書のレコードからはたどらない。
     *
     * @param  array{module: array<string, mixed>, record: array<string, mixed>}  $content  申請書のレコードの表示の口の結果
     * @return list<RelatedGroup>
     */
    public function of(
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
        WorkflowCase $case,
        array $content,
    ): array {
        $recordId = $case->record_id ?? throw new LogicException("申請した案件 {$case->id} がレコードを指していない");
        $codes = RecordModule::query()->whereNull('parent_record_module_id')->pluck('code', 'id')->all();
        $moduleCodes = array_map(strval(...), $codes);
        $moduleCode = MixedValues::stringOf($content['module']['code'] ?? null);
        $recordAccess = $this->viewAccess->canView($requester, $moduleCode, $recordId, $date);
        $application = $recordAccess ? $this->origins->ofApplication($content, $moduleCodes) : null;
        $applicationTargetOrigin = $this->applicationTargetOrigin(
            $requester, $date, $localeCode, $case, $content, $moduleCodes,
        );
        $groups = $this->groups->of($requester, $date, $applicationTargetOrigin, $application);

        return $this->placed($requester, $date, $localeCode, $case, $groups);
    }

    /**
     * 申請対象の起点(コンテンツに申請対象の項目が無い・値が空・見る人が閲覧できなければ null)。申請対象の値は申請書の表示の値から読む
     * (申請書は申請の後に変わらないので、最後の申請の写しと同じ値)。
     *
     * @param  array{module: array<string, mixed>, record: array<string, mixed>}  $content
     * @param  array<int, string>  $moduleCodes
     * @return RelatedOrigin|null
     */
    private function applicationTargetOrigin(
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
        WorkflowCase $case,
        array $content,
        array $moduleCodes,
    ): ?array {
        $applicationTarget = $this->applicationTargetValues->applicationTargetOf(CaseRows::flowOf($case));
        if ($applicationTarget === null) {
            return null;
        }

        $values = MixedValues::arrayOf($content['record']['values'] ?? null);
        $applicationTargetId = $this->applicationTargetValues->applicationTargetIdOf($applicationTarget, $values);

        if ($applicationTargetId === null) {
            return null;
        }

        $moduleCode = $applicationTarget['module'];

        return $this->viewAccess->canView($requester, $moduleCode, $applicationTargetId, $date)
            ? $this->origins->ofApplicationTarget(
                $requester, $moduleCode, $applicationTargetId, $date, $localeCode, $moduleCodes,
            )
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
