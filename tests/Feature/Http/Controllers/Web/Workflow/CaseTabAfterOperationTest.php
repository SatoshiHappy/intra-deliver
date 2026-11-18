<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\CaseOperationErrors;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 申請の詳細で始めた操作の後に、操作を始めたときのタブを開く(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md
 * の 3 節の表の各行)。申請操作の行は AdminCaseOperationControllerTest の成功のテスト。
 */
final class CaseTabAfterOperationTest extends TransactionalTestCase
{
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    private const INERTIA = ['X-Inertia' => 'true'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 元の画面(同じ申請の詳細)のクエリ tab と、移り先のクエリ tab(null = 既定のタブ)。キーの形 = 英小文字 1〜30 字。
     *
     * @return array<string, array{string, string|null}>
     */
    public static function tabs(): array
    {
        return [
            'a tab key' => ['?tab=flow&panel=comments', 'flow'],
            '1 letter' => ['?tab=a', 'a'],
            '30 letters' => ['?tab='.str_repeat('a', 30), str_repeat('a', 30)],
            '31 letters' => ['?tab='.str_repeat('a', 31), null],
            'empty' => ['?tab=', null],
            'not a key' => ['?tab=flow%20x', null],
            'an array' => ['?tab[]=flow', null],
            'no tab' => ['', null],
        ];
    }

    #[DataProvider('tabs')]
    public function test_a_process_from_the_same_detail_opens_its_tab(string $query, ?string $tab): void
    {
        $case = $this->caseNumbered(4);
        $this->actingAsUser('u0001');

        $expected = $tab === null ? ['case' => $case] : ['case' => $case, 'tab' => $tab];

        $this->from("/workflow/cases/{$case}{$query}")
            ->post("/workflow/cases/{$case}/withdraw", $this->withdrawalOf($case), self::INERTIA)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workflow.cases.show', $expected));
    }

    /**
     * 元の画面がほかの画面・ほかの申請・無いときは、クエリ tab があっても既定のタブ。
     *
     * @return array<string, array{string|null}>
     */
    public static function otherScreens(): array
    {
        return [
            'the case list' => ['/workflow/cases?tab=pending'],
            'another case' => ['/workflow/cases/999999999?tab=flow'],
            'the reapplication page of another case' => ['/workflow/cases/999999999/reapply?tab=flow'],
            'no previous screen' => [null],
        ];
    }

    #[DataProvider('otherScreens')]
    public function test_a_process_from_another_screen_opens_the_default_tab(?string $previous): void
    {
        $case = $this->caseNumbered(4);
        $this->actingAsUser('u0001');
        $request = $previous === null ? $this : $this->from($previous);

        $request->post("/workflow/cases/{$case}/withdraw", $this->withdrawalOf($case), self::INERTIA)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workflow.cases.show', ['case' => $case]));
    }

    /**
     * 元の画面(Referer)が無ければ、セッションの直前の URL が同じ申請の詳細でも既定のタブ(タブを切り替えてもセッションの URL は変わらない)。
     */
    public function test_a_process_without_a_referer_ignores_the_previous_url_of_the_session(): void
    {
        $case = $this->caseNumbered(4);
        $this->actingAsUser('u0001');
        $this->get("/workflow/cases/{$case}?tab=related")->assertOk();

        $this->post("/workflow/cases/{$case}/withdraw", $this->withdrawalOf($case), self::INERTIA)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workflow.cases.show', ['case' => $case]));
    }

    public function test_a_confirmation_opens_its_tab(): void
    {
        $case = $this->caseNumbered(3);
        $this->actingAsUser('u0010');

        $this->from("/workflow/cases/{$case}?tab=application")
            ->post("/workflow/cases/{$case}/confirm", [], self::INERTIA)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workflow.cases.show', ['case' => $case, 'tab' => 'application']));
    }

    /**
     * 再申請: 詳細のリンクがタブを再申請の画面へ渡し、再申請の画面から送った後にそのタブを開く。
     */
    public function test_a_reapplication_from_its_page_opens_the_tab_of_the_detail(): void
    {
        $case = $this->caseNumbered(4);
        $this->actingAsUser('u0001');

        $this->from("/workflow/cases/{$case}/reapply?tab=flow")
            ->post("/workflow/cases/{$case}/reapply", $this->reapplicationOf($case), self::INERTIA)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workflow.cases.show', ['case' => $case, 'tab' => 'flow']));
    }

    /**
     * 再申請の画面を開けない(案件 2 は再申請を待っていない)ときの詳細への戻りも、元の画面のタブを開く。形の違うタブは既定のタブ。
     */
    #[TestWith(['?tab=flow', 'flow'])]
    #[TestWith(['?tab=flow%20x', null])]
    public function test_the_reapplication_page_that_cannot_open_goes_back_to_the_tab(string $query, ?string $tab): void
    {
        $case = $this->caseNumbered(2);
        $this->actingAsUser('u0001');
        $expected = $tab === null ? ['case' => $case] : ['case' => $case, 'tab' => $tab];

        $this->from("/workflow/cases/{$case}{$query}")->get("/workflow/cases/{$case}/reapply")
            ->assertRedirect(route('workflow.cases.show', $expected))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => CaseOperationErrors::NOT_PERMITTED]);
    }

    /**
     * 再申請の画面(同じ申請)の形の違うタブは既定のタブ。
     */
    public function test_a_reapplication_from_its_page_with_a_malformed_tab_opens_the_default_tab(): void
    {
        $case = $this->caseNumbered(4);
        $this->actingAsUser('u0001');

        $this->from("/workflow/cases/{$case}/reapply?tab=".str_repeat('a', 31))
            ->post("/workflow/cases/{$case}/reapply", $this->reapplicationOf($case), self::INERTIA)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('workflow.cases.show', ['case' => $case]));
    }

    /**
     * @return array<string, mixed>
     */
    private function reapplicationOf(int $caseId): array
    {
        return [
            'revision' => $this->revisionOf($caseId), 'name' => '', 'comment' => null,
            'values' => $this->planValuesOfCase4(['budget' => 120]),
        ];
    }

    /**
     * @return array{revision: int, comment: null}
     */
    private function withdrawalOf(int $caseId): array
    {
        return ['revision' => $this->revisionOf($caseId), 'comment' => null];
    }
}
