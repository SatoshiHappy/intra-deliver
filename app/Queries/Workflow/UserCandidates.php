<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use App\Queries\Master\Api\MasterCandidates;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 人の候補(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.3 節): 基準日に有効なユーザーを共通マスタの公開の口
 * {@see MasterCandidates::usersOn()} で引く(並びは口の並び)。除く人の規則は掛けない(送った時の業務処理が判定する)。鍵を取らない。
 */
class UserCandidates
{
    /** 候補の上限 */
    public const LIMIT = 20;

    public function __construct(
        private CaseVisibility $visibility,
        private SubjectGroupMembership $membership,
        private MasterCandidates $candidates,
        private WorkflowPeople $people,
    ) {}

    /**
     * 候補を引いてよいかを確かめる(case があれば、閲覧できる申請した案件であること)。
     *
     * @param  mixed  $caseId  クエリの case(無ければ null)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できない・一時保存・読めない値のとき
     */
    public function authorize(AccessContext $context, mixed $caseId): void
    {
        if ($caseId !== null) {
            $this->caseOf($context, $caseId);
        }
    }

    /**
     * 候補(ゲストは空)。{@see self::authorize()} を通った入力を渡す。
     *
     * @param  mixed  $caseId  クエリの case
     * @param  mixed  $on  クエリの on
     * @return list<array{id: int, code: string, name: string|null, department: string|null, departmentId: int|null}>
     */
    public function of(AccessContext $context, mixed $caseId, mixed $on, string $keyword): array
    {
        if ($this->membership->effectiveUserId(Requester::fromAccessContext($context), $context->date) === null) {
            return [];
        }

        $date = $caseId === null
            ? self::dateOf($context->date, $on)
            : $this->baseDateOf($this->caseOf($context, $caseId));
        $users = $this->candidates->usersOn($keyword, $date, $context->localeCode, self::LIMIT);
        $departmentIds = $this->people->mainDepartmentIds(
            array_column($users, 'id'), $context->date, $context->localeCode,
        );

        return array_map(fn (array $user) => [
            'id' => $user['id'],
            'code' => $user['code'],
            'name' => $user['name'],
            'department' => $user['mainDepartmentName'],
            'departmentId' => $departmentIds[$user['id']] ?? null,
        ], $users);
    }

    /**
     * 基準日(case が無いとき。docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.3 節)。
     */
    private static function dateOf(CarbonImmutable $today, mixed $on): CarbonImmutable
    {
        $day = ApplicationBaseDates::parse($on);

        if ($day === null) {
            return $today;
        }

        $text = $day->format(ApplicationBaseDates::FORMAT);
        $isInRange = $text >= $today->format(ApplicationBaseDates::FORMAT)
            && $text <= ApplicationBaseDates::latest($today)->format(ApplicationBaseDates::FORMAT);

        return $isInRange ? $day : $today;
    }

    /**
     * @throws LogicException 申請した案件が申請基準日を持たないとき(検査制約が止めるので起きない)
     */
    private function baseDateOf(WorkflowCase $case): CarbonImmutable
    {
        $baseDate = $case->base_date ?? throw new LogicException("申請した案件 {$case->id} に申請基準日が無い");

        return CarbonImmutable::instance($baseDate);
    }

    /**
     * @throws ModelNotFoundException<WorkflowCase>
     */
    private function caseOf(AccessContext $context, mixed $caseId): WorkflowCase
    {
        $id = is_string($caseId) && preg_match('/^[1-9][0-9]{0,17}$/D', $caseId) === 1 ? (int) $caseId : 0;
        $query = WorkflowCase::query()->whereKey($id)->where('state', '<>', WorkflowCaseState::Draft);

        $requester = Requester::fromAccessContext($context);

        return $this->visibility->visible($query, $requester, $context->date)->firstOrFail();
    }
}
