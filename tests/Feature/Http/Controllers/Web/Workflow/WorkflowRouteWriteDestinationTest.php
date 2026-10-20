<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use Carbon\CarbonImmutable;
use Database\Seeders\SeedingDates;
use Database\Seeders\WorkflowSeeder;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * ルートの深さの「書き込み → 移り先の GET」(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 7 節、通しの回 T12 の表の組のうち初期データの版 3 = 分岐 B の分):
 * 指定・合議の票(可決・未決・可決できなくなった)・合議の子の差戻し・承認終了・票の引戻し・部長から分岐の前への差戻し・指定の誤り・処理対象者の候補と、
 * 版 2 の日の申請。HTTP で送り(画面と同じ Inertia の要求)、移り先を利用者ごとに GET して状態コードと props を確かめる。期待は通しの回の表と設計から書いた。
 * 案件は {@see AppliesOnTheBranchRoute} で作る(申請者 u0001、課長 u0002、部長 u0003)。合議のほかの子の 404 は u0016 で見る(u0010 は計画のフローの参照者 =
 * 経理部なので、処理していなくても詳細を開ける)。ほかのルートの形の組は {@see WorkflowRouteShapesWriteDestinationTest}。
 */
final class WorkflowRouteWriteDestinationTest extends TransactionalTestCase
{
    use AppliesOnTheBranchRoute;
    use FollowsCaseWrites;
    use OperatesOnRoutes, WritesWorkflowInputs {
        OperatesOnRoutes::departmentIdOf insteadof WritesWorkflowInputs;
        OperatesOnRoutes::revisionOf insteadof WritesWorkflowInputs;
    }
    use UsesSeededCases;
    use UsesSeededRecords;

    /** 課長の指定(合議 3 人・責任者 = 部長) */
    private const DESIGNATIONS = ['council' => ['u0009', 'u0010', 'u0016'], 'owner' => ['u0003']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // 申請基準日に初期データの日付(版 2 の日)を送るので、今日を初期データの流す日に固定する(今日の前後 1 年の規則で日がたっても拒まれないため)
        CarbonImmutable::setTestNow(SeedingDates::BASE.' 03:00:00 UTC');
    }

    /**
     * 課長が合議 3 人・責任者 1 人を指定して承認(予算 ≥ 300)→ 詳細: 部長が承認待ち、履歴の承認の行に指定、課長は引戻せる(防止の文言なし)。
     */
    public function test_the_section_chief_approves_with_the_designations_and_lands_on_the_detail(): void
    {
        $case = $this->applyOnBranch('u0001', 350);

        $this->assertLandsOnDetail($this->act('u0002', $case, 'approve', ['node_id' => $this->nodeOf($case, 'section_chief'), 'assignments' => $this->assignmentsFor($case, self::DESIGNATIONS)]), $case);

        $props = $this->detail('u0002', $case);
        $this->assertSame(['state' => 'waiting', 'name' => '部長'], $props['case']['currentSummary']);
        $this->assertSame([['関係部署の合議', ['u0009', 'u0010', 'u0016']], ['責任者', ['u0003']]], $this->lastAssignments($props));
        $this->assertSame([true, [['id' => $this->nodeOf($case, 'section_chief'), 'name' => '課長', 'blockedReason' => null]]], [$props['can']['pullBack'], $props['pullBackNodes']]);
    }

    /**
     * 指定の誤り(人数の外・重複・申請基準日に無効な人・防止のステップに申請者)は元の画面(詳細)へ戻って assignments.<id>、何も変わらない。
     */
    public function test_a_wrong_designation_returns_to_the_detail_with_the_error_and_changes_nothing(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $council = $this->nodeOf($case, 'council');
        $owner = $this->nodeOf($case, 'owner');
        $before = $this->snapshotOf($case);

        foreach ([
            'outside the range' => [['council' => ['u0009'], 'owner' => ['u0003']], $council],
            'duplicated' => [['council' => ['u0009', 'u0009', 'u0010'], 'owner' => ['u0003']], $council],
            'not effective' => [['council' => ['u0009', 'u0014'], 'owner' => ['u0003']], $council],
            'the applicant on a preventing step' => [['council' => ['u0009', 'u0010'], 'owner' => ['u0001']], $owner],
        ] as $label => [$designations, $nodeId]) {
            $this->act('u0002', $case, 'approve', ['node_id' => $this->nodeOf($case, 'section_chief'), 'assignments' => $this->assignmentsFor($case, $designations)])
                ->assertStatus(302)->assertRedirect("/workflow/cases/{$case}")->assertSessionHasErrors(["assignments.{$nodeId}"]);
            $this->assertSame($before, $this->snapshotOf($case), $label);
        }
    }

    /**
     * 合議の票で可決(3 人中 2 人目の承認)→ 票を入れた子の詳細は 200(合議 = 通過、残りの子 = 省略、責任者が承認待ち)、票を入れなかった子は 404。
     */
    public function test_the_deciding_vote_lands_on_the_detail_and_the_non_voter_loses_the_case(): void
    {
        $case = $this->caseAtCouncil();
        $this->approveOnBranch($case, 'u0009');

        $this->assertLandsOnDetail($this->act('u0010', $case, 'approve', ['node_id' => $this->nodeOf($case, 'council.2')]), $case);

        $props = $this->detail('u0010', $case);
        $this->assertSame(['council' => 'passed', 'council.3' => 'skipped', 'owner' => 'waiting'], $this->statesIn($props, $case, 'council', 'council.3', 'owner'));
        $this->assertTrue($props['councils'][0]['passed']);
        $this->assertDetailNotFound('u0016', $case);
    }

    /**
     * 合議の票で未決(最初の承認)→ 詳細: 進み具合 = 1 人が承認・必要 2 人、自分の子の行が処理済み(承認)。
     */
    public function test_an_undecided_vote_lands_on_the_detail_with_the_progress(): void
    {
        $case = $this->caseAtCouncil();

        $this->assertLandsOnDetail($this->act('u0009', $case, 'approve', ['node_id' => $this->nodeOf($case, 'council.1')]), $case);

        $council = $this->detail('u0009', $case)['councils'][0];
        $mine = array_values(array_filter($council['rows'], fn (array $row) => $row['person']['isMe'] ?? false));
        $this->assertSame([1, 2, false, ['approve']], [$council['approved'], $council['required'], $council['passed'], array_column($mine, 'action')]);
    }

    /**
     * 合議の 2 人目の否認で可決できなくなる → 否認した子の詳細は 200(完了・否認)、まだ処理していない子は 404。
     */
    public function test_the_vote_that_makes_the_council_fail_completes_the_case_as_rejected(): void
    {
        $case = $this->caseAtCouncil();
        $this->assertLandsOnDetail($this->act('u0010', $case, 'reject', ['node_id' => $this->nodeOf($case, 'council.2')]), $case);

        $this->assertLandsOnDetail($this->act('u0009', $case, 'reject', ['node_id' => $this->nodeOf($case, 'council.1')]), $case);

        $this->assertSame(['completed', 'rejected'], [$this->detail('u0009', $case)['case']['state'], $this->detail('u0009', $case)['case']['completionReason']]);
        $this->assertDetailNotFound('u0016', $case);
    }

    /**
     * 合議の子の承認終了 → 詳細: 完了・承認終了、ほかの子と親が省略。ほかの子は 404。
     */
    public function test_approving_early_in_the_council_completes_the_case_and_the_other_child_loses_it(): void
    {
        $case = $this->caseAtCouncil();

        $this->assertLandsOnDetail($this->act('u0009', $case, 'approve-early', ['node_id' => $this->nodeOf($case, 'council.1')]), $case);

        $props = $this->detail('u0009', $case);
        $this->assertSame(['completed', 'approved_early'], [$props['case']['state'], $props['case']['completionReason']]);
        $this->assertSame(['council' => 'skipped', 'council.2' => 'skipped', 'council.3' => 'skipped'], $this->statesIn($props, $case, 'council', 'council.2', 'council.3'));
        $this->assertDetailNotFound('u0016', $case);
    }

    /**
     * 合議の子が課長へ差戻す → 差戻した子の詳細は 200(課長が承認待ち)、ほかの子は 404。
     */
    public function test_a_council_child_sends_back_to_the_section_chief_and_the_other_child_loses_the_case(): void
    {
        $case = $this->caseAtCouncil();

        $this->assertLandsOnDetail($this->act('u0009', $case, 'send-back', ['node_id' => $this->nodeOf($case, 'council.1'), 'send_back_node_id' => $this->nodeOf($case, 'section_chief')]), $case);

        $this->assertSame(['state' => 'waiting', 'name' => '課長'], $this->detail('u0009', $case)['case']['currentSummary']);
        $this->assertDetailNotFound('u0016', $case);
    }

    /**
     * 可決を決めた票の引戻し → 詳細: 親 = 展開中、残りの子 = 承認待ち、責任者 = 到達していない。
     */
    public function test_pulling_back_the_deciding_vote_reopens_the_council(): void
    {
        $case = $this->caseAtCouncil();
        $this->approveOnBranch($case, 'u0009');
        $this->approveOnBranch($case, 'u0010');

        $this->assertLandsOnDetail($this->act('u0010', $case, 'pull-back', ['node_id' => $this->nodeOf($case, 'council.2')]), $case);

        $this->assertSame(
            ['council' => 'running', 'council.1' => 'done', 'council.2' => 'waiting', 'council.3' => 'waiting', 'owner' => 'unreached'],
            $this->statesIn($this->detail('u0010', $case), $case, 'council', 'council.1', 'council.2', 'council.3', 'owner'),
        );
    }

    /**
     * 部長が分岐の前の課長へ差戻す → 部長の詳細は 200(分岐開始は到達していない・分岐の辺の taken は null)、課長の詳細は 200(指定の初期値 = 前の指定)。
     */
    public function test_the_manager_sends_back_before_the_branch_and_the_section_chief_sees_the_previous_designations(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $this->approveOnBranch($case, 'u0002', self::DESIGNATIONS);
        $budget = $this->nodeOf($case, 'budget');

        $this->assertLandsOnDetail($this->act('u0003', $case, 'send-back', ['node_id' => $this->nodeOf($case, 'manager'), 'send_back_node_id' => $this->nodeOf($case, 'section_chief')]), $case);

        $props = $this->detail('u0003', $case);
        $this->assertSame(['budget' => 'unreached'], $this->statesIn($props, $case, 'budget'));
        $this->assertSame([null, null], array_column(array_values(array_filter($props['edges'], fn (array $edge) => $edge['from'] === $budget)), 'taken'));
        $designations = $this->detail('u0002', $case)['nodeActions'][0]['designations'];
        $this->assertSame([['関係部署の合議', ['u0009', 'u0010', 'u0016']], ['責任者', ['u0003']]], array_map(fn (array $row) => [$row['name'], array_column($row['initial'], 'code')], $designations));
    }

    /**
     * 合議の子の差戻しで課長が承認待ちに戻った後の処理対象者の候補: 課長は 200、差戻した子は 403(指定するノードを承認できない)。
     */
    public function test_the_assignee_candidates_after_a_send_back_to_the_section_chief(): void
    {
        $case = $this->caseAtCouncil();
        $this->assertLandsOnDetail($this->act('u0009', $case, 'send-back', ['node_id' => $this->nodeOf($case, 'council.1'), 'send_back_node_id' => $this->nodeOf($case, 'section_chief')]), $case);
        $url = "/workflow/cases/{$case}/assignee-candidates?".http_build_query(['node' => $this->nodeOf($case, 'council'), 'q' => 'u000']);

        $this->actingAsUser('u0002');
        $this->assertContains('u0009', array_column($this->getJson($url)->assertOk()->json('users'), 'code'));
        $this->actingAsUser('u0009');
        $this->getJson($url)->assertForbidden();
    }

    /**
     * 申請基準日 = 版 2 の期間の日(2 段目の見本と同じ日)で申請 → 詳細: 版 2、一本道(申請と承認だけ)。
     */
    public function test_an_application_on_a_day_of_version_2_lands_on_the_detail_of_the_straight_route(): void
    {
        $this->actingAsUser('u0001');

        $response = $this->post('/workflow/flows/project_plan/apply', ['name' => '', 'comment' => null, 'values' => $this->planValues('版 2 の日の計画'), 'base_date' => $this->baseDate()], ['X-Inertia' => 'true']);

        $response->assertStatus(302)->assertSessionHasNoErrors();
        $props = $this->detail('u0001', $case = $this->caseIdIn($response, '#/workflow/cases/(\d+)$#D'));
        $this->assertSame([2, WorkflowSeeder::DATES['stage2_plan_base_date'], ['state' => 'waiting', 'name' => '課長の承認']], [$props['case']['version'], $props['case']['baseDate'], $props['case']['currentSummary']]);
        $this->assertSame(['apply', 'approve', 'approve'], array_column($props['nodes'], 'kind'), "案件 {$case}");
    }

    /**
     * 合議まで進めた案件(予算 350 = 部長を通る。課長の指定 = 合議 u0009・u0010・u0016、責任者 u0003)。
     */
    private function caseAtCouncil(): int
    {
        $case = $this->applyOnBranch('u0001', 350);
        $this->approveOnBranch($case, 'u0002', self::DESIGNATIONS);
        $this->approveOnBranch($case, 'u0003');

        return $case;
    }
}
