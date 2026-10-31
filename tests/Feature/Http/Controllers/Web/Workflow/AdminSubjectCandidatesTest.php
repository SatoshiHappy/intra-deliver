<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\SendsFlowDefinitionRequests;
use Tests\Support\TransactionalTestCase;

/**
 * 定義の管理の対象者の候補 workflow.admin.subject-candidates(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節、認可と一覧.md 4.2 節):
 * 1 つの語で 4 種類(共通マスタの口 = 基準日は今日、ロールは認可の口)をまとめて引き、種類ごとの群(並び = ユーザー・組織・役職・ロール)で返す。
 * 語が空なら 4 つの空の群(上限が種類ごとなのは tests/Unit/Queries/Workflow/SubjectCandidatesTest.php)、主所属はユーザーだけ、
 * Cache-Control: private, no-store。ゲストと u0001 はルートの認可で 403。
 * q の誤りの 422 は AdminFlowDefinitionInputTest。
 */
final class AdminSubjectCandidatesTest extends TransactionalTestCase
{
    use SendsFlowDefinitionRequests;

    private const TYPES = ['user', 'department', 'company_post', 'role'];

    /**
     * 語ごとに、その種類の群にだけ候補が出る(ほかの種類の群は空でもよい)。候補の形と主所属。
     */
    public function test_one_keyword_finds_each_kind_in_its_group(): void
    {
        $this->actingAs($this->seededAccount(self::ADMIN));

        $cases = [
            ['達也', 'user', 'u0009', '総務部'],
            ['経理', 'department', 'accounting', null],
            ['課長', 'company_post', 'section-chief', null],
            ['一般', 'role', 'general_user', null],
        ];

        foreach ($cases as [$keyword, $type, $code, $mainDepartment]) {
            $response = $this->candidates($keyword);
            $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $groups = $this->groupsOf($response);
            $found = collect($groups[$type])->firstWhere('code', $code);
            $this->assertIsArray($found, "{$keyword} の {$type}");
            $this->assertSame(['id', 'code', 'name', 'mainDepartmentName'], array_keys($found));
            $this->assertSame($mainDepartment, $found['mainDepartmentName']);
        }
    }

    public function test_an_empty_keyword_finds_nothing(): void
    {
        $this->actingAs($this->seededAccount(self::ADMIN));

        $empty = ['groups' => array_map(fn (string $type) => ['type' => $type, 'candidates' => []], self::TYPES)];
        $this->getJson('/workflow/admin/subject-candidates')->assertOk()->assertExactJson($empty);
        $this->getJson('/workflow/admin/subject-candidates?q=')->assertOk()->assertExactJson($empty);
    }

    public function test_the_guest_and_a_user_without_the_route_are_forbidden(): void
    {
        $this->getJson('/workflow/admin/subject-candidates?q=u000')->assertForbidden();
        $this->actingAs($this->seededAccount('u0001'));
        $this->getJson('/workflow/admin/subject-candidates?q=u000')->assertForbidden();
    }

    /**
     * @return TestResponse<Response>
     */
    private function candidates(string $keyword): TestResponse
    {
        return $this->getJson('/workflow/admin/subject-candidates?q='.urlencode($keyword));
    }

    /**
     * 群を種類 => 候補の並びにする(群の並びは固定の 4 つ)。
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupsOf(TestResponse $response): array
    {
        $groups = $response->json('groups');
        $this->assertIsArray($groups);
        $this->assertSame(self::TYPES, array_column($groups, 'type'));

        return array_column($groups, 'candidates', 'type');
    }
}
