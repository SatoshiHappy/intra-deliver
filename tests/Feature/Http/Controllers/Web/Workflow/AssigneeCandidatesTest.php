<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Master\Api\MasterCandidates;
use App\Queries\Workflow\ApplicantApprovalGuard;
use DateTimeInterface;
use Tests\Support\TransactionalTestCase;

/**
 * 処理対象者の候補の入口(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1、案件の操作/処理対象者.md 5.2、認可と一覧.md 7 節の HTTP の分): 判定の順 = 案件を見られない 404 →
 * 指定されるノードでない 422 case → 指定するノードを承認できない 403 → 語の誤り 422。候補は申請基準日に有効なユーザー(20 件まで)で、申請者承認防止のノードは申請者を除く。
 * 案件は初期データの版 3(分岐 B)に u0001 が申請したもの(課長 u0002 が合議と責任者を指定する)。初期データを入れ、ルートの認可を外さない。
 */
final class AssigneeCandidatesTest extends TransactionalTestCase
{
    use AppliesOnTheBranchRoute;
    use UsesSeededCases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_designator_gets_the_candidates_without_the_applicant(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $this->actingAsUser('u0002');

        $response = $this->getJson($this->url($case, 'council', 'u000'))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $this->assertSame(['u0002', 'u0003', 'u0004', 'u0005', 'u0006', 'u0007', 'u0008', 'u0009'], array_column($response->json('users'), 'code'));
        $this->assertSame(['id', 'code', 'name', 'mainDepartmentName'], array_keys($response->json('users.0')));
        $this->assertSame([], $this->getJson($this->url($case, 'owner', 'u0001'))->assertOk()->json('users'));
        $this->assertSame([], $this->getJson($this->url($case, 'council', ''))->assertOk()->json('users'));
    }

    /**
     * 申請者として扱う人が 2 人(3b-1 の代理先を写した口の差し替え)でも、その数だけ多く引いて除き、候補は上限の 20 件(共通マスタの口は変えない = レビュー #5)。
     * 差し替えた共通マスタの結果の先頭に除く 2 人を入れる(除く処理が無ければ 2 人が候補に残って赤になる = #30)。
     */
    public function test_the_candidates_stay_at_the_limit_when_two_people_are_excluded(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $excluded = [$this->branchUserId('u0001'), $this->branchUserId('u0003')];
        $master = new class extends MasterCandidates
        {
            public int $limit = 0;

            /** @var list<int> 結果に入れる除く人(先頭に置く) */
            public array $excluded = [];

            public function __construct() {}

            public function usersOn(string $q, DateTimeInterface $d, string $localeCode, int $limit): array
            {
                $this->limit = $limit;
                $others = array_slice(array_values(array_diff(range(100001, 100000 + $limit), $this->excluded)), 0, $limit - count($this->excluded));

                return array_map(fn (int $id) => ['id' => $id, 'code' => "x{$id}", 'name' => null, 'mainDepartmentName' => null], [...$this->excluded, ...$others]);
            }
        };
        $master->excluded = $excluded;
        $guard = new class($excluded) extends ApplicantApprovalGuard
        {
            /** @param  non-empty-list<int>  $ids */
            public function __construct(private array $ids) {}

            public function applicantUserIds(WorkflowCase $case): array
            {
                return $this->ids;
            }
        };
        $this->app->instance(MasterCandidates::class, $master);
        $this->app->instance(ApplicantApprovalGuard::class, $guard);
        $this->actingAsUser('u0002');

        $ids = array_column($this->getJson($this->url($case, 'council', 'x'))->assertOk()->json('users'), 'id');

        $this->assertSame([22, 20, []], [$master->limit, count($ids), array_values(array_intersect($ids, $excluded))]);
    }

    /**
     * 案件を見られない 404 が、指定されるノードの誤り(422 case)・承認できない 403・語の誤りより先。
     */
    public function test_a_case_the_user_cannot_see_is_not_found_before_the_other_errors(): void
    {
        $case = $this->applyOnBranch('u0001', 350);

        $this->getJson("/workflow/cases/{$case}/assignee-candidates?node=x&q=".str_repeat('a', 101))->assertNotFound();
        $this->actingAsUser('u0008');
        $this->getJson("/workflow/cases/{$case}/assignee-candidates?node=x&q=".str_repeat('a', 101))->assertNotFound();
        $this->getJson('/workflow/cases/999999999/assignee-candidates?node=1')->assertNotFound();
    }

    /**
     * 指定されるノードでない(指定の種類が処理の時に指定でない・整数でない・ほかの案件のノード・無い)と 422 case で、403 より先。
     */
    public function test_a_node_that_is_not_designated_in_the_case_is_rejected_before_the_403(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $other = $this->applyOnBranch('u0001', 100);
        $this->actingAsUser('u0010');

        foreach ([$this->nodeIdOf($case, 'section_chief'), 'abc', $this->nodeIdOf($other, 'council'), 999999999, null] as $node) {
            $this->getJson("/workflow/cases/{$case}/assignee-candidates?".http_build_query(['node' => $node, 'q' => 'u']))
                ->assertStatus(422)->assertJsonValidationErrors(['case' => 'この申請は今この操作をできません']);
        }
    }

    /**
     * 指定するノード(課長)を承認できない人(見えるが処理対象者でない参照者 u0010・申請者・課長が済んだ後の課長)は 403 で、語の誤り(422)より先。語の誤りは承認できる人だけに出る。
     */
    public function test_who_cannot_approve_the_designator_is_forbidden_before_the_keyword_error(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $longKeyword = str_repeat('a', 101);

        foreach (['u0010', 'u0001'] as $code) {
            $this->actingAsUser($code);
            $this->getJson($this->url($case, 'council', $longKeyword))->assertForbidden();
        }

        $this->actingAsUser('u0002');
        $this->getJson($this->url($case, 'council', $longKeyword))->assertStatus(422)->assertJsonValidationErrors(['q' => '検索する語は 100 文字以内にしてください']);
        $this->approveOnBranch($case, 'u0002', ['council' => ['u0009', 'u0010'], 'owner' => ['u0003']]);
        $this->getJson($this->url($case, 'council', 'u000'))->assertForbidden();
    }

    public function test_rejects_a_keyword_that_is_not_valid_utf8(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $this->actingAsUser('u0002');

        $this->getJson($this->url($case, 'council', "\xFF"))->assertUnprocessable()
            ->assertJsonValidationErrors(['q' => '検索する語の指定が正しくありません']);
    }

    private function url(int $caseId, string $nodeCode, string $keyword): string
    {
        return "/workflow/cases/{$caseId}/assignee-candidates?".http_build_query(['node' => $this->nodeIdOf($caseId, $nodeCode), 'q' => $keyword]);
    }

    private function nodeIdOf(int $caseId, string $code): int
    {
        return WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('code', $code)->sole()->id;
    }
}
