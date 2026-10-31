<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\FlowDefinitionErrors;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Queries\Workflow\SubjectRows;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SendsFlowDefinitionRequests;
use Tests\Support\TransactionalTestCase;

/**
 * 定義の管理の入口 8 ルート(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.1 節、定義の管理.md 6 節の判定の順、認可と一覧.md 4.2 節・7 節):
 * 結果の優先 = ルートの拒否 > フロー・版・ノードが無い 404 > 封筒の 422 > 業務処理の 422(壊れた入力でも 404 が先)。成功は詳細の
 * 移り先とトースト、移り先の GET が 200、業務処理が書いたこと。拒否の組で何も変わらない。初期データのプロジェクト計画の承認で確かめる。
 */
final class AdminFlowDefinitionControllerTest extends TransactionalTestCase
{
    use SendsFlowDefinitionRequests;

    /** 画面(Inertia の router)が送る要求の印 */
    private const INERTIA = ['X-Inertia' => 'true'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function outsiders(): array
    {
        return ['guest' => [null], 'user u0001' => ['u0001']];
    }

    /**
     * ルートの execute が偽(ゲスト・u0001): 8 ルートのどれも 403。無いフローでも 403(ルートの拒否が 404 より先)。何も変わらない。
     */
    #[DataProvider('outsiders')]
    public function test_the_route_authorization_rejects_first(?string $userCode): void
    {
        if ($userCode !== null) {
            $this->actingAs($this->seededAccount($userCode));
        }

        $before = $this->definitionSnapshot();

        $routes = [...array_values($this->definitionRoutes()), ...array_values($this->definitionRoutes('no_flow'))];

        foreach ($routes as [$method, $url, $data]) {
            $this->sendJson($method, $url, $data)->assertForbidden();
        }

        $this->assertSame($before, $this->definitionSnapshot());
    }

    /**
     * システム管理者(u0004)は一覧・詳細・候補が 200。
     */
    public function test_the_reads_are_open_to_the_system_administrator(): void
    {
        $this->actingAs($this->seededAccount(self::ADMIN));

        $this->get('/workflow/admin/flows')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Workflow/Admin/FlowIndex'));
        $this->get('/workflow/admin/flows/'.self::PLAN)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Workflow/Admin/FlowShow'));
        $this->getJson('/workflow/admin/subject-candidates?type=role&q=user')->assertOk();
    }

    /**
     * 404: 無いフロー(詳細と書き込み)、そのフローに無い版(別のフローにはある版 3・無い番号)、その版に無いノード(別の版にだけある budget・無いコード)。
     * 壊れた入力でも 404。引数の形に合わない値はルートに当たらない(404)。何も変わらない。
     */
    public function test_a_missing_flow_version_or_node_is_not_found(): void
    {
        $this->actingAs($this->seededAccount(self::ADMIN));
        $before = $this->definitionSnapshot();
        $broken = ['start_date' => 'x', 'referrers' => 'x', 'target_kind' => ['x'], 'min_assignees' => 'x'];
        $missing = $this->definitionRoutes('missing_flow');
        unset($missing['workflow.admin.flows.index'], $missing['workflow.admin.subject-candidates']);
        $issue = '/workflow/admin/flows/issue_resolution/versions';
        $plan = '/workflow/admin/flows/'.self::PLAN.'/versions';
        $notFound = [
            ...array_values(array_map(fn (array $route) => [$route[0], $route[1]], $missing)),
            ['post', "{$issue}/3/disable"], ['post', "{$issue}/3/enable"], ['put', "{$issue}/3/nodes/budget"],
            ['post', "{$plan}/99/disable"], ['put', "{$plan}/1/nodes/budget"], ['put', "{$plan}/3/nodes/nothing"],
            ['get', '/workflow/admin/flows/Project-Plan'], ['post', "{$plan}/x/disable"],
            ['post', "{$plan}/1234567890/enable"], ['put', "{$plan}/3/nodes/Budget"],
        ];

        foreach ($notFound as [$method, $url]) {
            $this->sendJson($method, $url, $broken)->assertNotFound();
        }

        $this->assertSame($before, $this->definitionSnapshot());
    }

    /**
     * 封筒の誤りは業務処理より先(開始日が日付でなければ範囲を見ない)。業務処理の 422 = 定義の管理.md 6 節のキーと文言(同じ状態への無効・有効、
     * 最後の版の開始日以前、規則に合わない指定するステップ、無い相手の参照者)。何も変わらない。
     */
    public function test_the_envelope_comes_before_the_business_rules(): void
    {
        $this->actingAs($this->seededAccount(self::ADMIN));
        $before = $this->definitionSnapshot();
        $base = '/workflow/admin/flows/'.self::PLAN;
        $last = $this->planVersion(3)->start_date->toDateString();
        $owner = $this->nodeInput(3, 'owner', ['designator_node_code' => 'manager']);
        $nobody = ['referrers' => [['type' => 'user', 'id' => 999999999]]];

        $notDate = ['start_date' => '開始日は日付で入力してください'];
        $this->assertErrors('post', "{$base}/versions", ['start_date' => '2026/1/1'], $notDate);
        $deleted = ['version' => FlowDefinitionErrors::VERSION_ALREADY_DELETED];
        $this->assertErrors('post', "{$base}/versions/1/disable", [], $deleted);
        $enabled = ['version' => FlowDefinitionErrors::VERSION_ALREADY_ENABLED];
        $this->assertErrors('post', "{$base}/versions/2/enable", [], $enabled);
        $afterLast = sprintf(FlowDefinitionErrors::START_DATE_AFTER_LAST, 3, $last);
        $this->assertErrors('post', "{$base}/versions", ['start_date' => $last], ['start_date' => $afterLast]);
        $designator = ['designator_node_code' => FlowDefinitionErrors::DESIGNATOR_INVALID];
        $this->assertErrors('put', "{$base}/versions/3/nodes/owner", $owner, $designator);
        $missing = ['referrers.0' => FlowDefinitionErrors::SUBJECT_MISSING];
        $this->assertErrors('put', "{$base}/referrers", $nobody, $missing);

        $this->assertSame($before, $this->definitionSnapshot());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function writes(): array
    {
        $show = '/workflow/admin/flows/'.self::PLAN;

        return [
            'referrers' => ['workflow.admin.flows.referrers.update', "{$show}?tab=referrers", '参照者を保存しました'],
            'store' => ['workflow.admin.versions.store', "{$show}?tab=versions&version=4", '版を追加しました'],
            'disable' => ['workflow.admin.versions.disable', "{$show}?tab=versions&version=2", '版を無効にしました'],
            'enable' => ['workflow.admin.versions.enable', "{$show}?tab=versions&version=1", '版を有効にしました'],
            'nodes' => [
                'workflow.admin.nodes.update', "{$show}?tab=diagram&version=3&node=section_chief", 'ステップの設定を保存しました',
            ],
        ];
    }

    /**
     * 成功: 詳細の移り先(POST は 302、PUT は 303)とトースト(画面 README の 1.1 節の文)、移り先の GET が 200、業務処理が書いたこと。
     */
    #[DataProvider('writes')]
    public function test_each_write_lands_on_the_detail_with_its_toast(
        string $name,
        string $location,
        string $toast,
    ): void {
        $this->actingAs($this->seededAccount(self::ADMIN));
        [$method, $url, $data] = $this->definitionRoutes()[$name];

        $server = $this->transformHeadersToServerVars(self::INERTIA);

        $response = $this->call(strtoupper($method), $url, $data, [], [], $server);

        $response->assertStatus($method === 'put' ? 303 : 302)->assertSessionHasNoErrors()
            ->assertRedirect(url($location))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => $toast]);
        $this->assertWritten($name);
        $this->get($location)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Workflow/Admin/FlowShow'));
    }

    private function assertWritten(string $name): void
    {
        $flow = WorkflowFlow::query()->where('code', self::PLAN)->sole();
        $numbers = WorkflowFlowVersion::query()->where('workflow_flow_id', $flow->id)->orderBy('number')
            ->get()->map(fn (WorkflowFlowVersion $version) => [$version->number, $version->is_deleted])->all();
        $groupId = (int) $flow->authz_subject_group_id;
        $referrers = app(SubjectRows::class)->of([$groupId], CarbonImmutable::today(), 'ja');
        $written = match ($name) {
            'workflow.admin.flows.referrers.update' => array_column($referrers[$groupId], 'type'),
            'workflow.admin.nodes.update' => $this->planNode(3, 'section_chief')->prevents_applicant_approval,
            default => $numbers,
        };
        $expected = [
            'workflow.admin.flows.referrers.update' => ['department', 'role'],
            'workflow.admin.versions.store' => [[1, true], [2, false], [3, false], [4, false]],
            'workflow.admin.versions.disable' => [[1, true], [2, true], [3, false]],
            'workflow.admin.versions.enable' => [[1, false], [2, false], [3, false]],
            'workflow.admin.nodes.update' => false,
        ];

        $this->assertSame($expected[$name], $written);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $errors
     */
    private function assertErrors(string $method, string $url, array $data, array $errors): void
    {
        $response = $this->sendJson($method, $url, $data);

        $response->assertUnprocessable()->assertJsonValidationErrors($errors);
        $this->assertSame(array_keys($errors), array_keys((array) $response->json('errors')));
    }
}
