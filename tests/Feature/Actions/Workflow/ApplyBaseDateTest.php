<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\AddRouteVersion;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\CreateWorkflowFlow;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Actions\Workflow\UpdateDraft;
use App\Actions\Workflow\WorkflowRouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\Department;
use App\Models\Master\DepartmentMembership;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsRoutePlans;
use Tests\Support\TransactionalTestCase;

/**
 * 申請基準日と版(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.1、同時実行と検査.md 13 節・14 節の「申請基準日と版」)。操作の基準日(今日)= 2026-10-13。
 * テストのフローの版: 版 1 = 段 2 の一本道(2025-04-01〜2026-01-01)、版 2 = R2(2026-01-01〜2027-04-01)、版 3 = R3(2027-04-01〜)。期待の文言は 13 節の表から書いた。
 */
final class ApplyBaseDateTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsRoutePlans;

    /** @var array<int, int> 版の番号 → 版の id */
    private array $versions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
        $this->versions = [1 => $this->versionId(1), 2 => $this->addVersion($this->r2()), 3 => $this->addVersion($this->r3(), '2027-04-01')];
    }

    /**
     * 過去・未来の日付で、その日に有効な版が写る(申請基準日・版を案件に持つ)。範囲の端(今日の前後ちょうど 1 年)は受ける。
     */
    public function test_the_version_effective_on_the_base_date_is_copied(): void
    {
        $expected = ['2025-10-13' => [1, 'approval1'], '2025-12-31' => [1, 'approval1'], '2026-01-01' => [2, 'sync'], '2027-03-31' => [2, 'sync'], '2027-04-01' => [3, 'chief'], '2027-10-13' => [3, 'chief']];

        foreach ($expected as $baseDate => [$number, $secondCode]) {
            $case = $this->applyOn($baseDate);

            $this->assertSame([$this->versions[$number], $baseDate], [$case->workflow_flow_version_id, $case->base_date?->toDateString()], $baseDate);
            $this->assertSame($secondCode, WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->where('position', 2)->value('code'), $baseDate);
            $this->assertRouteInvariants($case);
        }
    }

    public function test_a_missing_or_malformed_or_out_of_range_base_date_is_rejected(): void
    {
        $counts = $this->workflowCounts();
        $cases = [
            '' => '申請基準日を入力してください', '   ' => '申請基準日を入力してください',
            '2026-02-30' => '申請基準日は日付で入力してください', '2026/10/13' => '申請基準日は日付で入力してください', '2026-10-13T00:00' => '申請基準日は日付で入力してください', 'today' => '申請基準日は日付で入力してください',
            '2025-10-12' => '申請基準日は今日の前後 1 年以内の日付を入力してください', '2027-10-14' => '申請基準日は今日の前後 1 年以内の日付を入力してください',
        ];

        foreach ($cases as $input => $message) {
            $this->assertSame(['base_date' => $message], $this->applyErrors((string) $input), (string) $input);
        }

        $this->assertSame(['base_date' => '申請基準日を入力してください'], $this->applyErrors(null));
        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 閏日の今日(2028-02-29)の前後 1 年は 2027-02-28 と 2029-02-28(月末に丸める。3 月 1 日へずらさない)。
     */
    public function test_the_range_from_a_leap_day_ends_on_february_28(): void
    {
        $leapDay = '2028-02-29';

        $this->assertSame([2, 3], [$this->numberOf($this->applyOn('2027-02-28', today: $leapDay)), $this->numberOf($this->applyOn('2029-02-28', today: $leapDay))]);
        $this->assertSame(['base_date' => '申請基準日は今日の前後 1 年以内の日付を入力してください'], $this->applyErrors('2027-02-27', today: $leapDay));
        $this->assertSame(['base_date' => '申請基準日は今日の前後 1 年以内の日付を入力してください'], $this->applyErrors('2029-03-01', today: $leapDay));
    }

    /**
     * 無効の版の期間と、最初の版の前は 422(申請基準日に有効な版が無い)。今日の版で申請できるので 403 にはならない。
     */
    public function test_a_disabled_version_or_a_date_before_the_first_version_has_no_version(): void
    {
        $this->addVersion($this->r2(), '2027-06-01');
        WorkflowFlowVersion::query()->whereKey($this->versions[3])->update(['is_deleted' => true]);
        $content = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->workflowContent()->sole();
        app(CreateWorkflowFlow::class)->execute('late_flow', '遅いフロー', null, $content->code, $this->linearNodes(), CarbonImmutable::parse('2026-01-01'), null, [], 20);
        $counts = $this->workflowCounts();

        $this->assertSame(['base_date' => '申請基準日 2027-05-01 に申請できる版がありません'], $this->applyErrors('2027-05-01'));
        $this->assertSame(['base_date' => '申請基準日 2025-12-31 に申請できる版がありません'], $this->applyErrors('2025-12-31', 'late_flow'));
        $this->assertSame($counts, $this->workflowCounts());
    }

    /**
     * 403 は今日の版で決まり、申請基準日に依らない。今日の版で申請できる人が、申請基準日の版で申請できない日を選ぶと 422 base_date。
     */
    public function test_the_403_follows_todays_version_and_the_base_date_version_gives_422(): void
    {
        $this->createFlow('narrow', '版 1 は u0005 だけ', applicants: [$this->userCondition('u0005')]);
        $narrow = WorkflowFlow::query()->where('code', 'narrow')->sole();
        app(AddRouteVersion::class)->execute('narrow', CarbonImmutable::parse('2026-01-01'), ...$this->r4Route());
        $this->createFlow('closed', '版 2 は u0005 だけ');
        app(AddRouteVersion::class)->execute('closed', CarbonImmutable::parse('2026-01-01'), ...$this->r4Route('u0005'));

        $this->assertSame(['base_date' => '申請基準日 2025-12-31 には、このフローを申請できません'], $this->applyErrors('2025-12-31', 'narrow'));
        $this->assertSame($narrow->id, $this->applyOn('2026-10-13', 'narrow')->workflow_flow_id);
        $this->assertThrows(fn () => $this->applyOn('2025-12-31', 'closed'), AuthorizationException::class, 'このフローを申請できません');
    }

    /**
     * 担当組織は申請基準日のカレント組織(u0001 が 2026-06-01 に東京営業課から人事部へ異動したとする)。
     */
    public function test_the_department_is_the_current_department_on_the_base_date(): void
    {
        $u0001 = $this->seededUser('u0001');
        $tokyo = Department::query()->where('code', 'tokyo-sales')->sole();
        DepartmentMembership::query()->where('user_id', $u0001->id)->where('department_id', $tokyo->id)->update(['end_date' => '2026-06-01']);
        $hr = Department::query()->where('code', 'human-resources')->sole();
        DepartmentMembership::factory()->create(['department_set_id' => $hr->department_set_id, 'department_id' => $hr->id, 'user_id' => $u0001->id, 'is_main' => true, 'sort_key' => 0, 'start_date' => '2026-06-01', 'end_date' => null, 'is_deleted' => false]);

        $this->assertSame([$tokyo->id, $hr->id], [$this->applyOn('2026-05-31')->department_id, $this->applyOn('2026-06-01')->department_id]);
    }

    /**
     * 一時保存は今日の版で 403 を判定し申請基準日を検証せずに保つ。一時保存からの申請では入力の申請基準日で版を選ぶ(一時保存の値から変えられる)。
     * 再申請は申請基準日と版を変えない。
     */
    public function test_a_draft_keeps_the_base_date_unvalidated_and_the_application_from_it_uses_the_input(): void
    {
        $u0001 = $this->seededUser('u0001');
        $caseId = app(CreateDraft::class)->execute($this->contextOf($u0001), self::FLOW_CODE, new DraftInput(null, null, [], [], 'あした'));
        app(UpdateDraft::class)->execute($this->contextOf($u0001), $caseId, 0, new DraftInput(null, null, [], [], ' 2025-12-01 '));
        $this->assertSame('2025-12-01', WorkflowCase::query()->findOrFail($caseId)->draft_input['base_date'] ?? null);

        app(ApplyCase::class)->executeFromDraft($this->contextOf($u0001), $caseId, 1, $this->projectInput(), '2026-03-01');

        $case = WorkflowCase::query()->findOrFail($caseId);
        $this->assertSame([$this->versions[2], '2026-03-01'], [$case->workflow_flow_version_id, $case->base_date?->toDateString()]);
    }

    private function applyOn(string $baseDate, string $flowCode = self::FLOW_CODE, ?string $today = null): WorkflowCase
    {
        $result = app(ApplyCase::class)->execute($this->contextOf($this->seededUser('u0001'), date: $today), $flowCode, $this->projectInput(), $baseDate);

        return WorkflowCase::query()->findOrFail($result->caseId);
    }

    /**
     * @return array<string, string>
     */
    private function applyErrors(?string $baseDate, string $flowCode = self::FLOW_CODE, ?string $today = null): array
    {
        return $this->validationErrors(fn () => app(ApplyCase::class)->execute($this->contextOf($this->seededUser('u0001'), date: $today), $flowCode, $this->projectInput(), $baseDate));
    }

    /**
     * 案件が写した版の番号。
     */
    private function numberOf(WorkflowCase $case): int
    {
        return (int) array_search($case->workflow_flow_version_id, $this->versions, true);
    }

    private function versionId(int $number): int
    {
        return WorkflowFlowVersion::query()->whereHas('workflowFlow', fn ($flow) => $flow->where('code', self::FLOW_CODE))->where('number', $number)->sole()->id;
    }

    /**
     * 段 2 の一本道のノード(申請 = 一般利用者、承認 1 = u0002)。
     *
     * @return list<WorkflowRouteNodeInput>
     */
    private function linearNodes(): array
    {
        $apply = $this->applyNode();

        return [
            new WorkflowRouteNodeInput('apply', '申請', WorkflowNodeKind::Apply, $apply->targetConditions, ['ja' => '申請']),
            new WorkflowRouteNodeInput('approval1', '承認1', WorkflowNodeKind::Approve, [$this->userCondition('u0002')], ['ja' => '承認1']),
        ];
    }

    /**
     * 申請 → 承認 1(u0002)の形つきの版(申請のノードの対象者 = $applicantCode、無ければ一般利用者)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function r4Route(?string $applicantCode = null): array
    {
        $apply = $applicantCode === null ? $this->applyNode() : new RouteNodeInput('apply', '申請', WorkflowNodeKind::Apply, WorkflowTargetKind::Fixed, [$this->userCondition($applicantCode)], ['ja' => '申請']);

        return [[$apply, $this->approveNode('approval1', 'u0002', ['apply'])], $this->chain(['apply', 'approval1'])];
    }
}
