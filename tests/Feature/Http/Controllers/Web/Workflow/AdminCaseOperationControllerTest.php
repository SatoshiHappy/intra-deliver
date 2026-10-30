<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\CaseOperationErrors;
use App\Actions\Workflow\RetargetValidator;
use App\Enums\Workflow\WorkflowFlowAction;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\GrantsCaseOperationRoutes;
use Tests\Support\GrantsFlowPermissions;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\TransactionalTestCase;

/**
 * 申請操作の入口 workflow.admin.cases.*(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、案件の操作/案件操作.md の 15.1 節、
 * 認可と一覧.md の 4 節・6 節): 結果の優先 = ルートの拒否 > 案件が無い・閲覧できない 404 > operate が無い 403 > 422 case > 422 入力のキー
 * (壊れた入力でも 404・403 が先)。成功は詳細(削除は申請操作の一覧)へ移りトースト、業務処理の履歴の行ができる。
 * 案件 = R1 を課長(u0002)が合議 u0005・u0009、責任者 u0010 で承認した未完了の案件。管理者 = u0004、今日 = 2026-10-13。
 * ルートの execute・operate はテストの中で利用者ごとに足す(初期データはシステム管理者だけ)。
 */
final class AdminCaseOperationControllerTest extends TransactionalTestCase
{
    use GrantsCaseOperationRoutes;
    use GrantsFlowPermissions;
    use OperatesAsCaseManager;

    /** 書き込みの 5 ルート(URL の末尾 → メソッド) */
    private const WRITES = [
        'retarget' => 'post', 'reexpand' => 'post', 'move' => 'post', 'assign' => 'post', '' => 'delete',
    ];

    /** 画面(Inertia の router)が送る要求の印 */
    private const INERTIA = ['X-Inertia' => 'true'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addVersion($this->r1());
    }

    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function outsiders(): array
    {
        return ['guest' => [null, false], 'user u0001' => ['u0001', false], 'operate only u0009' => ['u0009', true]];
    }

    /**
     * ルートの execute が偽(ゲスト・u0001・operate だけの u0009): 一覧と 5 ルートのどれも 403。無い案件でも 403(ルートの拒否が 404 より先)。
     */
    #[DataProvider('outsiders')]
    public function test_the_route_authorization_rejects_first(?string $userCode, bool $operates): void
    {
        $case = $this->councilCase();
        $before = $this->historyCount();

        if ($userCode !== null) {
            $user = $this->seededUser($userCode);
            $this->actingAs($user);

            if ($operates) {
                $this->grantFlow($user, self::FLOW_CODE, WorkflowFlowAction::Operate);
            }
        }

        $this->get('/workflow/admin/cases')->assertForbidden();

        foreach (array_keys(self::WRITES) as $route) {
            $this->sendJson($route, $case->id, $this->input($route, $case))->assertForbidden();
            $this->sendJson($route, $case->id + 100000, [])->assertForbidden();
        }

        $this->assertSame($before, $this->historyCount());
    }

    /**
     * execute が真で、案件が無い(管理者 u0004)・閲覧できない(案件に関わらない u0006 に execute と operate を足す)と 404。壊れた入力でも 404。
     */
    public function test_a_missing_or_invisible_case_is_not_found(): void
    {
        $case = $this->councilCase();
        $outsider = $this->seededUser('u0006');
        $this->grantCaseOperationRoutes($outsider);
        $this->grantFlow($outsider, self::FLOW_CODE, WorkflowFlowAction::Operate);
        $broken = ['revision' => 'x', 'node_id' => 'x', 'user_ids' => 'x', 'direction' => ['x']];

        foreach (array_keys(self::WRITES) as $route) {
            $this->actingAs($this->seededUser(self::MANAGER));
            $this->sendJson($route, $case->id + 100000, $broken)->assertNotFound();
            $this->actingAs($outsider);
            $this->sendJson($route, $case->id, $this->input($route, $case))->assertNotFound();
            $this->sendJson($route, $case->id, $broken)->assertNotFound();
        }

        $this->assertTrue(WorkflowCase::query()->whereKey($case->id)->exists());
    }

    /**
     * execute が真で閲覧できるが operate が偽(課長で処理した u0002 に execute だけを足す): 5 ルートとも 403(壊れた入力でも)。一覧は開けて、
     * read の無い利用者には案件が出ない。
     */
    public function test_a_user_without_operate_is_forbidden(): void
    {
        $case = $this->councilCase();
        $user = $this->seededUser('u0002');
        $this->grantCaseOperationRoutes($user);
        $this->actingAs($user);
        $before = $this->historyCount();

        foreach (array_keys(self::WRITES) as $route) {
            $this->sendJson($route, $case->id, $this->input($route, $case))->assertForbidden();
            $this->sendJson($route, $case->id, ['revision' => null])->assertForbidden();
        }

        $this->get('/workflow/admin/cases')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Admin/CaseOperations')->where('cases.total', 0)->where('flows', []));
        $this->assertSame($before, $this->historyCount());
    }

    /**
     * 422 case(業務処理の文言): 改訂番号が古い・ノードがこの案件のものでない・表で許されない(現在のノードでない責任者の処理担当者の変更、
     * 指定を変更できない合議の指定の変更)。封筒の誤り(改訂番号が無い)は前例の入口と同じく 422 revision。何も変わらない。
     */
    public function test_the_case_rejections(): void
    {
        $case = $this->councilCase();
        $other = $this->councilCase();
        $this->actingAs($this->seededUser(self::MANAGER));
        $before = $this->historyCount();
        $ids = $this->labelIds($case);

        $stale = ['revision' => $this->revisionOf($case) - 1];
        $this->assertCaseError('retarget', $case, $stale, CaseOperationErrors::STALE_REVISION);
        $foreign = ['node_id' => $this->labelIds($other)['council.1']];
        $this->assertCaseError('reexpand', $case, $foreign, CaseOperationErrors::NOT_PERMITTED);
        $this->assertCaseError('retarget', $case, ['node_id' => $ids['owner']], CaseOperationErrors::NOT_PERMITTED);
        $this->assertCaseError('assign', $case, ['node_id' => $ids['council']], CaseOperationErrors::NOT_PERMITTED);
        $input = $this->input('', $case);
        unset($input['revision']);
        $this->sendJson('', $case->id, $input)->assertUnprocessable()
            ->assertJsonValidationErrors(['revision' => '改訂番号を指定してください']);

        $this->assertSame($before, $this->historyCount());
    }

    /**
     * 422 入力のキー(業務処理の文言): 処理担当者の変更の自分自身(user_ids)、移動の種類が外の値(direction)、戻り先が候補に無い(send_back_node_id)、
     * 指定の変更の人数(user_ids。責任者は 1 人)。何も変わらない。
     */
    public function test_the_input_rejections(): void
    {
        $case = $this->councilCase();
        $this->actingAs($this->seededUser(self::MANAGER));
        $before = $this->historyCount();
        $manager = $this->idsOf([self::MANAGER]);
        $owner = '責任者の処理担当者は 1 人を指定してください';

        $this->assertInputError('retarget', $case, ['user_ids' => $manager], ['user_ids' => RetargetValidator::SELF]);
        $sideways = ['direction' => 'sideways'];
        $this->assertInputError('move', $case, $sideways, ['direction' => CaseOperationErrors::MOVE_DIRECTION]);
        $outside = ['send_back_node_id' => $this->labelIds($case)['owner']];
        $this->assertInputError('move', $case, $outside, ['send_back_node_id' => CaseOperationErrors::MOVE_BACK_NODE]);
        $this->assertInputError('assign', $case, ['user_ids' => []], ['user_ids' => $owner]);

        $this->assertSame($before, $this->historyCount());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function operations(): array
    {
        return [
            'retarget' => ['retarget', '処理担当者を変更しました', 'retarget'],
            'reexpand' => ['reexpand', '処理担当者を再展開しました', 'reexpand'],
            'move' => ['move', 'ステップを移動しました', 'move_back'],
            'assign' => ['assign', '指定を変更しました', 'reassign'],
        ];
    }

    /**
     * 成功: 詳細へ 302 とトースト(画面 README の 1.1 節の文)、業務処理の履歴の行(処理者 = 管理者)。移り先の GET が 200。
     */
    #[DataProvider('operations')]
    public function test_an_operation_lands_on_the_detail_with_its_toast(
        string $route,
        string $toast,
        string $action,
    ): void {
        $case = $this->councilCase();
        $this->actingAs($this->seededUser(self::MANAGER));

        $url = "/workflow/admin/cases/{$case->id}/{$route}";
        $response = $this->post($url, $this->input($route, $case), self::INERTIA);

        $detail = route('workflow.cases.show', ['case' => $case->id]);
        $response->assertStatus(302)->assertSessionHasNoErrors()->assertRedirect($detail)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $toast]);
        $this->assertSame([$action, self::MANAGER], array_slice($this->latestRow($case), 0, 2));
        $this->get($detail)->assertOk();
    }

    /**
     * 申請の削除: 申請操作の一覧へ 303 とトースト「申請を削除しました」。案件は消え、詳細は 404。一覧は 200 でページ Workflow/Admin/CaseOperations。
     */
    public function test_the_deletion_lands_on_the_list(): void
    {
        $case = $this->councilCase();
        $this->actingAs($this->seededUser(self::MANAGER));

        $response = $this->delete("/workflow/admin/cases/{$case->id}", $this->input('', $case), self::INERTIA);

        $response->assertStatus(303)->assertRedirect(route('workflow.admin.cases.index'))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '申請を削除しました']);
        $this->assertFalse(WorkflowCase::query()->whereKey($case->id)->exists());
        $this->get(route('workflow.cases.show', ['case' => $case->id]))->assertNotFound();
        $this->get('/workflow/admin/cases?flow='.self::FLOW_CODE)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Admin/CaseOperations')
            ->where('query.flow', self::FLOW_CODE)->where('cases.total', 0));
    }

    /**
     * {case} が [0-9]{1,18} に合わない値はルートに当たらない(404)。
     */
    public function test_a_case_argument_out_of_the_form_matches_no_route(): void
    {
        $this->actingAs($this->seededUser(self::MANAGER));

        $this->postJson('/workflow/admin/cases/abc/retarget', [])->assertNotFound();
        $this->deleteJson('/workflow/admin/cases/1234567890123456789', [])->assertNotFound();
    }

    /**
     * R1 を予算 100(部長なし)で申請し、課長(u0002)が合議 = u0005・u0009、責任者 = u0010 を指定して承認した案件。
     */
    private function councilCase(): WorkflowCase
    {
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0005', 'u0009'], 'owner' => ['u0010']]);

        return $case;
    }

    /**
     * 画面が送る正しい形(今の改訂番号。処理担当者の変更・再展開・移動 = 合議の子 1、指定の変更 = 責任者、削除 = 改訂番号だけ)。
     *
     * @return array<string, mixed>
     */
    private function input(string $route, WorkflowCase $case): array
    {
        $ids = $this->labelIds($case);
        $node = ['node_id' => $ids['council.1'], 'comment' => '管理者の操作'];

        return ['revision' => $this->revisionOf($case), ...match ($route) {
            'retarget' => [...$node, 'user_ids' => $this->idsOf(['u0007'])],
            'reexpand' => $node,
            'move' => [...$node, 'direction' => 'back', 'send_back_node_id' => $ids['section_chief']],
            'assign' => ['node_id' => $ids['owner'], 'comment' => null, 'user_ids' => $this->idsOf(['u0007'])],
            default => [],
        }];
    }

    /**
     * 422 の中身を読むため JSON で送る(削除は DELETE、ほかは POST)。
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    private function sendJson(string $route, int $caseId, array $data): TestResponse
    {
        $url = $route === '' ? "/workflow/admin/cases/{$caseId}" : "/workflow/admin/cases/{$caseId}/{$route}";

        return self::WRITES[$route] === 'delete' ? $this->deleteJson($url, $data) : $this->postJson($url, $data);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function assertCaseError(string $route, WorkflowCase $case, array $overrides, string $message): void
    {
        $this->assertInputError($route, $case, $overrides, ['case' => $message]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $errors
     */
    private function assertInputError(string $route, WorkflowCase $case, array $overrides, array $errors): void
    {
        $response = $this->sendJson($route, $case->id, [...$this->input($route, $case), ...$overrides]);

        $response->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->assertSame(array_keys($errors), array_keys((array) $response->json('errors')));
    }

    private function historyCount(): int
    {
        return WorkflowCaseHistory::query()->count();
    }
}
