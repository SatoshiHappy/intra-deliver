<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SendsFlowDefinitionRequests;
use Tests\Support\TransactionalTestCase;

/**
 * 定義の管理の対象者の候補 workflow.admin.subject-candidates(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節、認可と一覧.md 4.2 節):
 * 4 種類の候補(共通マスタの口 = 基準日は今日、ロールは認可の口)、語が空なら空、主所属はユーザーだけ、Cache-Control: private, no-store。
 * ゲストと u0001 はルートの認可で 403。type の誤りの 422 は AdminFlowDefinitionInputTest。
 */
final class AdminSubjectCandidatesTest extends TransactionalTestCase
{
    use SendsFlowDefinitionRequests;

    /**
     * @return array<string, array{string, string, string, string|null}>
     */
    public static function kinds(): array
    {
        return [
            'user' => ['user', '達也', 'u0009', '総務部'],
            'department' => ['department', '経理', 'accounting', null],
            'company_post' => ['company_post', '課長', 'section-chief', null],
            'role' => ['role', '一般', 'general_user', null],
        ];
    }

    #[DataProvider('kinds')]
    public function test_each_kind_finds_candidates(
        string $type,
        string $keyword,
        string $code,
        ?string $mainDepartment,
    ): void {
        $this->actingAs($this->seededAccount(self::ADMIN));

        $response = $this->getJson("/workflow/admin/subject-candidates?type={$type}&q=".urlencode($keyword));

        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $rows = $response->json('candidates');
        $this->assertIsList($rows);
        $candidates = collect($rows);
        $found = $candidates->firstWhere('code', $code);
        $this->assertIsArray($found);
        $this->assertSame(['id', 'code', 'name', 'mainDepartmentName'], array_keys($found));
        $this->assertSame($mainDepartment, $found['mainDepartmentName']);
        $this->assertLessThanOrEqual(20, $candidates->count());
    }

    public function test_an_empty_keyword_finds_nothing(): void
    {
        $this->actingAs($this->seededAccount(self::ADMIN));

        $url = '/workflow/admin/subject-candidates';
        $this->getJson("{$url}?type=user")->assertOk()->assertExactJson(['candidates' => []]);
        $this->getJson("{$url}?type=role&q=")->assertOk()->assertExactJson(['candidates' => []]);
    }

    public function test_the_guest_and_a_user_without_the_route_are_forbidden(): void
    {
        $this->getJson('/workflow/admin/subject-candidates?type=user&q=u000')->assertForbidden();
        $this->actingAs($this->seededAccount('u0001'));
        $this->getJson('/workflow/admin/subject-candidates?type=user&q=u000')->assertForbidden();
    }
}
