<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\CaseOperationErrors;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 保留・保留解除・担当変更・確認のルート(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、認可と一覧.md の 4 節、
 * 案件の操作/操作.md の 7 節・10.1〜10.4 節): 成功は 302 で詳細へ戻りトースト、閲覧できない案件とゲストは業務処理の 404
 * (判定の順の 1 = 鍵と閲覧が先)、判定の拒否は業務処理の 422 がそのまま出る。初期データの見本(初期データ.md の 4 節)を使い、
 * ルートの認可を外さない。WF-000003 = 部長(u0003)が承認待ち(u0009 は u0003 の承認の代理先、u0010 は未確認)、
 * WF-000013 = 部長が保留中(u0009 が u0003 として保留)。
 */
final class CaseHoldRoutesTest extends TransactionalTestCase
{
    use UsesSeededCases;
    use UsesSeededRecords;
    use WritesWorkflowInputs;

    /** 画面(Inertia の router)が送る要求の印 */
    private const INERTIA = ['X-Inertia' => 'true'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_hold_as_the_principal_returns_to_the_detail_with_a_toast(): void
    {
        $case = $this->caseNumbered(3);

        $response = $this->send('u0009', $case, 'hold', ['principal_user_id' => $this->userIdOf('u0003')]);

        $this->assertLandsWith($response, $case, '保留しました');
        $this->assertSame(['hold', 'u0003', 'u0009'], $this->latestRowOf($case));
    }

    public function test_a_hold_without_the_principal_is_processed_by_the_user_himself(): void
    {
        $case = $this->caseNumbered(3);

        $response = $this->send('u0003', $case, 'hold', ['principal_user_id' => null]);

        $this->assertLandsWith($response, $case, '保留しました');
        $this->assertSame(['hold', 'u0003', null], $this->latestRowOf($case));
    }

    public function test_a_release_returns_to_the_detail_with_a_toast(): void
    {
        $case = $this->caseNumbered(13);

        $this->assertLandsWith($this->send('u0003', $case, 'release'), $case, '保留を解除しました');
        $this->assertSame(['release', 'u0003', null], $this->latestRowOf($case));
    }

    public function test_a_transfer_returns_to_the_detail_with_a_toast(): void
    {
        $case = $this->caseNumbered(3);

        $response = $this->send('u0003', $case, 'transfer', ['to_user_id' => $this->userIdOf('u0004')]);

        $this->assertLandsWith($response, $case, '担当を変更しました');
        $history = WorkflowCaseHistory::query()->where('workflow_case_id', $case)->latest('id')->firstOrFail();
        $people = WorkflowCaseHistoryUser::query()->where('workflow_case_history_id', $history->id)
            ->pluck('user_id')->all();
        $this->assertSame(['transfer', [$this->userIdOf('u0004')]], [$history->action->value, $people]);
    }

    public function test_a_confirmation_returns_to_the_detail_with_a_toast(): void
    {
        $case = $this->caseNumbered(3);
        $count = fn () => WorkflowCaseConfirmation::query()->where('workflow_case_id', $case)->count();
        $before = $count();
        $this->actingAsUser('u0010');

        $response = $this->post("/workflow/cases/{$case}/confirm", [], self::INERTIA);

        $this->assertLandsWith($response, $case, '確認しました');
        $this->assertSame($before + 1, $count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function routes(): array
    {
        return ['hold' => ['hold'], 'release' => ['release'], 'transfer' => ['transfer'], 'confirm' => ['confirm']];
    }

    /**
     * 閲覧できない人(案件にも代理にも関わらない u0005)とゲストは 404(業務処理の判定の順の 1。ゲストはどの案件も閲覧できない)。
     */
    #[DataProvider('routes')]
    public function test_a_case_not_visible_is_not_found_for_a_user_and_a_guest(string $route): void
    {
        $case = $this->caseNumbered(3);
        $before = WorkflowCaseHistory::query()->count();

        $this->postJson("/workflow/cases/{$case}/{$route}", $this->input($case))->assertNotFound();
        $this->actingAsUser('u0005');
        $this->postJson("/workflow/cases/{$case}/{$route}", $this->input($case))->assertNotFound();

        $this->assertSame($before, WorkflowCaseHistory::query()->count());
    }

    /**
     * 役割の無い人(課長で承認した u0002)の保留・担当変更、保留者側でない人(u0009 自身として)の保留解除、
     * 確認対象者でない人(u0002 の WF-000002)の確認は、業務処理の 422 case。
     *
     * @return array<string, array{string, string, int, string}>
     */
    public static function rejections(): array
    {
        return [
            'hold by the previous processor' => ['u0002', 'hold', 3, CaseOperationErrors::NOT_PERMITTED],
            'transfer by the previous processor' => ['u0002', 'transfer', 3, CaseOperationErrors::NOT_PERMITTED],
            'release by a proxy as himself' => ['u0009', 'release', 13, CaseOperationErrors::NOT_PERMITTED],
            'confirm by a user who is not a confirmer' => ['u0002', 'confirm', 2, CaseOperationErrors::NO_CONFIRMATION],
        ];
    }

    #[DataProvider('rejections')]
    public function test_a_rejection_is_the_unprocessable_of_the_operation(
        string $user,
        string $route,
        int $number,
        string $message,
    ): void {
        $case = $this->caseNumbered($number);
        $before = WorkflowCaseHistory::query()->count();

        $this->sendJson($user, $case, $route, ['to_user_id' => $this->userIdOf('u0004')])
            ->assertUnprocessable()->assertJsonValidationErrors(['case' => $message]);

        $this->assertSame($before, WorkflowCaseHistory::query()->count());
    }

    public function test_a_stale_revision_is_rejected_by_the_operation(): void
    {
        $case = $this->caseNumbered(3);

        $this->sendJson('u0003', $case, 'hold', ['revision' => $this->revisionOf($case) - 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['case' => CaseOperationErrors::STALE_REVISION]);
    }

    /**
     * 代理元でない id(u0009 の代理元は u0003 だけ)は、業務処理の 422 principal_user_id。
     */
    public function test_a_processor_who_is_not_a_principal_is_rejected_by_the_operation(): void
    {
        $case = $this->caseNumbered(3);

        $this->sendJson('u0009', $case, 'hold', ['principal_user_id' => $this->userIdOf('u0002')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['principal_user_id' => CaseOperationErrors::PRINCIPAL]);
    }

    /**
     * 画面と同じ Inertia の要求で送る。
     *
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function send(string $user, int $caseId, string $route, array $overrides = []): TestResponse
    {
        $this->actingAsUser($user);

        return $this->post("/workflow/cases/{$caseId}/{$route}", $this->input($caseId, $overrides), self::INERTIA);
    }

    /**
     * 422 の中身を読むため JSON で送る。
     *
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function sendJson(string $user, int $caseId, string $route, array $overrides = []): TestResponse
    {
        $this->actingAsUser($user);

        return $this->postJson("/workflow/cases/{$caseId}/{$route}", $this->input($caseId, $overrides));
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertLandsWith(TestResponse $response, int $caseId, string $toast): void
    {
        $response->assertStatus(302)->assertSessionHasNoErrors()
            ->assertRedirect(route('workflow.cases.show', ['case' => $caseId]))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $toast]);
    }

    /**
     * 処理のダイアログが送る形(今の改訂番号と現在のノード。確認は読まない)。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(int $caseId, array $overrides = []): array
    {
        $node = $this->currentNodeIdOf($caseId);

        return ['revision' => $this->revisionOf($caseId), 'node_id' => $node, 'comment' => null, ...$overrides];
    }

    /**
     * 最新の履歴の行の [種別, 処理者のコード, 実行者のコード]。
     *
     * @return array{string, string, string|null}
     */
    private function latestRowOf(int $caseId): array
    {
        $row = WorkflowCaseHistory::query()->with(['actor', 'executor'])->where('workflow_case_id', $caseId)
            ->latest('id')->firstOrFail();

        return [$row->action->value, (string) $row->actor?->code, $row->executor?->code];
    }
}
