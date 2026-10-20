<?php

namespace Tests\Feature\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Master\Api\CurrentDepartment;
use Carbon\CarbonImmutable;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 計画が書く到達日時と到達の前処理者・起点の組織(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 9 節、
 * 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.1 節、設計/ワークフロー/案件の運用/データモデル/表.md の 3.2 節):
 * 前のノードからの到達(申請・承認)は到達日時と前処理者(その操作の処理者)と起点の組織を書く。後ろからの到達(差戻し先・引戻しの X)は
 * 到達日時だけを書き直し、写しから戻したノードは到達日時を変えない。ルート = R1(予算 100 = 部長を通らない)、申請者 u0001。
 */
final class CaseReachWritingTest extends TransactionalTestCase
{
    use OperatesOnRoutes;

    private const T1 = '2026-10-19 09:00:00';

    private const T2 = '2026-10-19 10:00:00';

    private const T3 = '2026-10-19 11:00:00';

    private const T4 = '2026-10-19 12:00:00';

    public function test_the_application_reaches_the_first_approval_from_the_applicant(): void
    {
        $case = $this->appliedAt(self::T1);

        $this->assertSame([self::T1, 'u0001', $case->department_id], $this->reachOf($case, 'section_chief'));
        $this->assertSame([null, null, null], $this->reachOf($case, 'apply'));
    }

    public function test_an_approval_expands_the_council_from_the_approver(): void
    {
        $case = $this->councilAt(self::T2);

        $fromChief = [self::T2, 'u0002', $this->mainDepartmentOf('u0002')];

        foreach (['council.1', 'council.2', 'council.3'] as $label) {
            $this->assertSame($fromChief, $this->reachOf($case, $label), $label);
        }

        $this->assertSame([self::T1, 'u0001', $case->department_id], $this->reachOf($case, 'section_chief'));
    }

    /**
     * 合議の票が 2 人そろって可決すると、後のノードの前処理者は可決させた票の処理者になる(省いた子の到達日時は変えない)。
     */
    public function test_the_node_after_several_processors_is_reached_from_the_deciding_processor(): void
    {
        $case = $this->councilAt(self::T2);
        $this->approve($case, 'council.1', 'u0004');
        $this->travelTo(CarbonImmutable::parse(self::T3));

        $this->approve($case, 'council.2', 'u0005');

        $this->assertSame([self::T3, 'u0005', $this->mainDepartmentOf('u0005')], $this->reachOf($case, 'owner'));
        $this->assertSame(['skipped', self::T2], $this->stateAndReachedTime($case, 'council.3'));
    }

    /**
     * 差戻し先と引戻しの X は到達日時だけを書き直し、前処理者は前のノードからの到達のまま。引戻しで写しから戻したノードは到達日時を変えない。
     */
    public function test_send_back_and_pull_back_rewrite_only_the_reached_time(): void
    {
        $case = $this->councilAt(self::T2);
        $this->travelTo(CarbonImmutable::parse(self::T3));
        $this->sendBackOn($case, 'council.1', 'u0004', 'section_chief');

        $this->assertSame([self::T3, 'u0001', $case->department_id], $this->reachOf($case, 'section_chief'));

        $this->travelTo(CarbonImmutable::parse(self::T4));
        $this->pullBackOn($case, 'council.1', 'u0004');

        $this->assertSame([self::T4, 'u0002', $this->mainDepartmentOf('u0002')], $this->reachOf($case, 'council.1'));
        $this->assertSame(['waiting[u0009]', self::T2], $this->stateAndReachedTime($case, 'council.3'));
        $this->assertSame(['done', self::T3], $this->stateAndReachedTime($case, 'section_chief'));
    }

    private function appliedAt(string $time): WorkflowCase
    {
        $this->addVersion($this->r1());
        $this->travelTo(CarbonImmutable::parse($time));
        [$case] = $this->applyOnRoute(['budget' => 100]);

        return $case;
    }

    /**
     * T1 に申請し、$time に課長 u0002 が合議(u0004・u0005・u0009。人数 2 で可決)と責任者 u0010 を指定して承認した案件。
     */
    private function councilAt(string $time): WorkflowCase
    {
        $case = $this->appliedAt(self::T1);
        $this->travelTo(CarbonImmutable::parse($time));
        $this->approve($case, 'section_chief', 'u0002', [
            'council' => ['u0004', 'u0005', 'u0009'],
            'owner' => ['u0010'],
        ]);

        return $case;
    }

    /**
     * ノードの [到達日時(UTC の文字列), 前処理者のコード, 起点の組織]。
     *
     * @return array{string|null, string|null, int|null}
     */
    private function reachOf(WorkflowCase $case, string $label): array
    {
        $node = WorkflowCaseNode::query()->with('previousUser')->findOrFail($this->labelIds($case)[$label]);

        return [$node->reached_at?->toDateTimeString(), $node->previousUser?->code, $node->previous_department_id];
    }

    /**
     * ノードの [状態(処理対象者つき), 到達日時]。
     *
     * @return array{string, string|null}
     */
    private function stateAndReachedTime(WorkflowCase $case, string $label): array
    {
        return [$this->shape($case)[$label], $this->reachOf($case, $label)[0]];
    }

    /**
     * 選んだ組織を渡さない操作の起点の組織(前処理者の申請基準日の主所属)。
     */
    private function mainDepartmentOf(string $userCode): ?int
    {
        return app(CurrentDepartment::class)
            ->departmentIdOn($this->seededUser($userCode)->id, null, $this->operationDay());
    }
}
