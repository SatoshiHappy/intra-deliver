<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Master\Department;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Master\Api\MasterCandidates;
use DateTimeInterface;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 人の候補の入口 workflow.user-candidates(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節・1.3 節): q が空なら空、基準日 =
 * if(case){案件の申請基準日(見えない案件は 404)}elseif(on が今日〜今日 + 1 年){on}else{今日}、20 件まで、
 * 応答の形 { id, code, name, department, departmentId }(departmentId = 名前の札の「組織図で見る」の行き先 = 設計/ワークフロー/申請の周辺/メニューと導線.md 2.1)、
 * ゲストは空。今日 = 2026-10-13。u0015(林 翔太)は 2026-11-01 に入社、u0014(木村 綾)は 2025-10-01 に退職。初期データを入れ、ルートの認可を外さない。
 */
final class UserCandidateControllerTest extends TransactionalTestCase
{
    use RunsWorkflowOperations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->createFlow();
    }

    public function test_returns_the_users_effective_today_in_the_shape_of_the_screen(): void
    {
        $this->actingAs($this->seededUser('u0008'));

        $response = $this->getJson($this->url('山田'))->assertOk();

        $response->assertHeader('Cache-Control', 'no-store, private');
        $u0010 = $this->seededUser('u0010')->id;
        $accounting = Department::query()->where('code', 'accounting')->sole()->id;
        $expected = [
            'id' => $u0010, 'code' => 'u0010', 'name' => '山田 恵', 'department' => '経理部', 'departmentId' => $accounting,
        ];
        $this->assertSame([$expected], $response->json('users'));
        $this->assertSame([], $this->candidateCodes(''));
        $this->assertSame([], $this->candidateCodes('翔太'));
    }

    /**
     * on は今日から今日 + 1 年(両端を含む)ならその日。範囲の外・読めない値は今日。
     */
    public function test_the_date_on_reads_only_a_date_within_a_year_from_today(): void
    {
        $this->actingAs($this->seededUser('u0008'));

        $this->assertSame(['u0015'], $this->candidateCodes('翔太', '&on=2026-11-05'));
        $this->assertSame(['u0015'], $this->candidateCodes('翔太', '&on=2027-10-13'));
        $this->assertSame([], $this->candidateCodes('翔太', '&on=2027-10-14'));
        $this->assertSame([], $this->candidateCodes('翔太', '&on=2026-11-31'));
        $this->assertSame([], $this->candidateCodes('翔太', '&on[]=2026-11-05'));
        $this->assertSame([], $this->candidateCodes('木村', '&on=2025-09-01'));
        $joined = $this->getJson($this->url('翔太', '&on=2026-11-05'))->json('users.0');
        // 所属の名前は基準日、札の行き先の主所属は今日(今日はまだ所属が無い = 設計/ワークフロー/申請の周辺/メニューと導線.md 2.1)
        $this->assertSame(['第二営業部', null], [$joined['department'], $joined['departmentId']]);
    }

    /**
     * case があれば案件の申請基準日(on は読まない)。見えない・一時保存・無い・読めない案件は 404(語の誤りより先)。
     */
    public function test_the_case_gives_its_base_date_and_an_invisible_case_is_not_found(): void
    {
        $applicant = $this->seededUser('u0008');
        $case = $this->applyAs($applicant, baseDate: '2026-11-05');
        $draft = WorkflowCase::factory()->draft()->createOne([
            'workflow_flow_id' => WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id,
            'user_id' => $applicant->id,
        ]);
        $this->actingAs($applicant);

        $this->assertSame(['u0015'], $this->candidateCodes('翔太', "&case={$case->id}&on=2026-10-13"));

        foreach ([(string) $draft->id, '999999999', 'abc', '0'] as $caseId) {
            $this->getJson($this->url('翔太', "&case={$caseId}"))->assertNotFound();
        }

        $this->actingAs($this->seededUser('u0013'));
        $longKeyword = str_repeat('a', 101);
        $this->getJson("/workflow/user-candidates?q={$longKeyword}&case={$case->id}")->assertNotFound();
    }

    /**
     * ゲストは空の配列(見えない案件は 404)。語は 100 文字まで(超えると 422 q)。
     */
    public function test_a_guest_gets_nothing_and_the_keyword_is_limited(): void
    {
        $case = $this->applyAs($this->seededUser('u0008'));

        $this->assertSame([], $this->candidateCodes('渡辺'));
        $this->getJson($this->url('渡辺', "&case={$case->id}"))->assertNotFound();

        $this->actingAs($this->seededUser('u0008'));
        $this->getJson('/workflow/user-candidates?q='.str_repeat('a', 100))->assertOk();
        $this->getJson('/workflow/user-candidates?q='.str_repeat('a', 101))
            ->assertUnprocessable()->assertJsonValidationErrors(['q']);
    }

    /**
     * 共通マスタの口へ上限 20 を渡し、口の並びのまま返す。
     */
    public function test_asks_the_master_for_twenty_users(): void
    {
        $master = new class extends MasterCandidates
        {
            /** @var list<int> */
            public array $limits = [];

            public function __construct() {}

            public function usersOn(string $q, DateTimeInterface $d, string $localeCode, int $limit): array
            {
                $this->limits[] = $limit;

                return [
                    ['id' => 2, 'code' => 'b', 'name' => null, 'mainDepartmentName' => null],
                    ['id' => 1, 'code' => 'a', 'name' => 'A', 'mainDepartmentName' => 'D'],
                ];
            }
        };
        $this->app->instance(MasterCandidates::class, $master);
        $this->actingAs($this->seededUser('u0008'));

        $this->assertSame(['b', 'a'], $this->candidateCodes('x'));
        $this->assertSame([20], $master->limits);
    }

    /**
     * @return list<string> 候補のコード(並びの順)
     */
    private function candidateCodes(string $q, string $more = ''): array
    {
        $users = $this->getJson($this->url($q, $more))->assertOk()->json('users');

        return array_column($users, 'code');
    }

    private function url(string $q, string $more = ''): string
    {
        return '/workflow/user-candidates?q='.rawurlencode($q).$more;
    }
}
