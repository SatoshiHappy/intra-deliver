<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ServiceAction;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\EffectivePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * 認可設定の画面の行の出し方の表(docs の 設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.1 の表の 6 行)とルートの束の判定(2.2)と
 * 「表示の範囲」の選択肢の元(2.3 = 深さ 1 の行)。初期データを入れ、システム管理者(u0004)で開く(日付は OverviewControllerTest と同じに固定)。
 *
 * | # | セット | グループの種類 | 確かめ |
 * |---|---|---|---|
 * | 1 | 設定不可のセット | どれでも | どの種類の格子にも出ない(ほかの種類のポリシーを足しても) |
 * | 2 | http-services | 先頭 | 出す・子を持つ・判定は null |
 * | 3 | http-services | 上のフォルダ | 出す・子を持つ・判定は null |
 * | 4 | http-services | ルートの束 | 出す・子を持たない・判定 = 配下のすべてのルートの判定 |
 * | 5 | http-services | ルートの行 | 出さない |
 * | 6 | ほかのセット | どれでも | リソースを持つ行は判定あり、持たない行は null |
 */
final class OverviewRowTableTest extends TransactionalTestCase
{
    use EditsAuthz;
    use ReadsAuthzGrid;

    /** 上のフォルダ(#3) */
    private const FOLDERS = [
        'http-services.authz', 'http-services.master', 'http-services.records', 'http-services.records.admin',
        'http-services.workflow', 'http-services.workflow.admin',
    ];

    /** ルートの束(#4) */
    private const BUNDLES = [
        'http-services.authz.settings', 'http-services.master.organization-chart',
        'http-services.records.admin.categories', 'http-services.records.admin.comments',
        'http-services.records.admin.modules', 'http-services.workflow.admin.cases',
        'http-services.workflow.admin.flows', 'http-services.workflow.admin.proxies',
    ];

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
        $this->administrator = $this->user('u0004');
    }

    /**
     * #1: 設定不可のセットのグループは、ほかの種類のポリシーを足しても、どの種類の格子にも出ない。
     */
    public function test_no_group_of_the_unmanaged_set_is_a_row_of_any_type(): void
    {
        $column = $this->userColumn('u0001');
        $this->place('http-services-unmanaged.menu', $column, PolicyEffect::Permit, 'im-menu-group', 'read');
        $this->place('http-services-unmanaged.workflow', $column, PolicyEffect::Permit, 'record-module', 'read');
        $this->actingAs($this->administrator);

        foreach ($this->props($this->get(self::URL))['resourceTypes'] as $resourceType) {
            $codes = array_column($this->grid($this->get(self::URL."?type={$resourceType}"))['rows'], 'code');

            $this->assertSame(
                [],
                array_values(array_filter(
                    $codes,
                    fn (string $code) => str_starts_with($code, ServiceAction::UNMANAGED_SET_CODE),
                )),
                $resourceType,
            );
        }
    }

    /**
     * #2〜#5: 画面・処理の格子の行は、先頭・上のフォルダ・ルートの束だけ(ルートの行が無い)。先頭と上のフォルダは子を持ち判定が null、
     * 束は子を持たない(開閉の記号の元が無い)で判定がある。
     */
    public function test_the_service_grid_is_the_head_the_folders_and_the_bundles(): void
    {
        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL))['rows'];
        $codes = array_column($rows, 'code');
        $parentIds = array_column($rows, 'parentId');
        $expected = ['http-services', ...self::FOLDERS, ...self::BUNDLES];
        sort($expected);
        $sorted = $codes;
        sort($sorted);

        $this->assertSame($expected, $sorted);
        $this->assertCount(15, $rows);

        foreach ($rows as $row) {
            $isBundle = in_array($row['code'], self::BUNDLES, true);
            $this->assertSame(
                [! $isBundle, $isBundle],
                [in_array($row['resourceGroupId'], $parentIds, true), $row['judgements'] !== null],
                "{$row['code']} の子と判定",
            );
        }
    }

    /**
     * 「表示の範囲」の選択肢の元 = 深さ 1 の行(行の並びのまま。4 つともフォルダなので登録した順)。
     */
    public function test_the_rows_of_depth_one_are_the_four_modules_in_order(): void
    {
        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL))['rows'];
        $depthOne = array_values(array_filter($rows, fn (array $row) => $row['depth'] === 1));

        $this->assertSame(['認可', '基本データ', '業務データ', 'ワークフロー'], array_column($depthOne, 'name'));
    }

    /**
     * 束の名前は管理のメニューの画面名にそろえる(「業務モジュールの管理」でなく「業務モジュール」)。フォルダ「基本データ」の子は束「組織図」だけ。
     */
    public function test_the_bundle_names_follow_the_screen_names_of_the_menu(): void
    {
        $response = $this->actingAs($this->administrator)->get(self::URL);
        $rows = $this->grid($response)['rows'];
        $names = array_column($rows, 'name', 'code');
        $masterId = $this->row($response, 'http-services.master')['resourceGroupId'];

        $this->assertSame('業務モジュール', $names['http-services.records.admin.modules']);
        $this->assertSame('基本データ', $names['http-services.master']);
        $this->assertSame('組織図', $names['http-services.master.organization-chart']);
        $this->assertNotContains('業務モジュールの管理', $names);
        $this->assertSame(
            ['http-services.master.organization-chart'],
            array_column(array_filter($rows, fn (array $row) => $row['parentId'] === $masterId), 'code'),
        );
    }

    /**
     * #6: ほかのセットの格子は今の規則のまま(リソースを持つ行は判定があり、持たない行は null)。
     */
    #[TestWith(['im-menu-group'])]
    #[TestWith(['record-module'])]
    #[TestWith(['workflow-flow'])]
    public function test_the_other_grids_judge_the_rows_holding_the_resource(string $resourceType): void
    {
        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL."?type={$resourceType}"))['rows'];
        $holders = AuthzResource::query()->where('resource_type', $resourceType)
            ->pluck('authz_resource_group_id')->all();

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertSame(
                in_array($row['resourceGroupId'], $holders, true),
                $row['judgements'] !== null,
                "{$resourceType} の {$row['code']}",
            );
        }
    }

    /**
     * #4: 束の判定は、配下のどのルートの判定とも同じ(束ごとに配下を全部照らす)。判定する人 = 自分・ゲスト・u0001。
     */
    #[TestWith(['self'])]
    #[TestWith(['guest'])]
    #[TestWith(['u0001'])]
    public function test_the_judgement_of_a_bundle_is_that_of_every_route_under_it(string $judge): void
    {
        $userId = match ($judge) {
            'self' => $this->administrator->id,
            'guest' => null,
            default => $this->user($judge)->id,
        };
        $query = $judge === 'self' ? '' : '?user='.($userId ?? 'guest');
        $rows = $this->grid($this->actingAs($this->administrator)->get(self::URL.$query))['rows'];
        $requester = new Requester($userId, null);
        $checked = 0;

        foreach ($rows as $row) {
            if (! in_array($row['code'], self::BUNDLES, true)) {
                continue;
            }

            $routeIds = AuthzResourceGroup::query()->where('parent_id', $row['resourceGroupId'])->pluck('id');

            foreach ($routeIds as $routeId) {
                $explanation = app(AuthorizationQuery::class)
                    ->explain($requester, $routeId, 'execute', CarbonImmutable::now());
                $deciding = array_map(
                    fn (EffectivePolicy $policy) => $policy->subjectGroupId,
                    $explanation->decidingPolicies(),
                );
                sort($deciding);
                $actual = $row['judgements'][0]['decidingSubjectGroupIds'];
                sort($actual);

                $this->assertSame(
                    [strtolower($explanation->result->name), $deciding],
                    [$row['judgements'][0]['result'], $actual],
                    "{$row['code']} の配下 {$routeId}",
                );
                $checked++;
            }
        }

        $this->assertSame(53, $checked);
    }

    /**
     * 束「組織図」のセル = ゲストユーザ・認証済みユーザの直接の許可とシステム管理者の継承の許可。ゲストでは許可、u0001 で束「認可設定」は禁止。
     * フォルダ「基本データ」はシステム管理者の継承の許可だけ(全員に開いた許可は束に置く)。
     */
    public function test_the_bundle_of_the_organization_chart_and_the_judges(): void
    {
        $response = $this->actingAs($this->administrator)->get(self::URL);
        [$guest, $authenticated, $administrator] = array_column($this->grid($response)['subjects'], 'id');
        $master = $this->row($response, 'http-services.master.organization-chart');

        $this->assertSame(
            [[$administrator, 'permit', 'http-services']],
            $this->cells($this->row($response, 'http-services.master'), 'execute'),
        );

        $this->assertSame(
            [[$guest, 'permit', null], [$authenticated, 'permit', null], [$administrator, 'permit', 'http-services']],
            $this->cells($master, 'execute'),
        );
        $this->assertSame(['code' => 'http-services', 'name' => '画面・処理'], $master['settings'][2]['inheritedFrom']);
        $this->assertSame(
            [[
                'action' => 'execute',
                'result' => 'permit',
                'decidingSubjectGroupIds' => [$authenticated, $administrator],
            ]],
            $master['judgements'],
        );
        $asGuest = $this->get(self::URL.'?user=guest');
        $this->assertSame('permit', $this->judgementOf($asGuest, 'http-services.master.organization-chart'));
        $u0001 = $this->user('u0001')->id;
        $asU0001 = $this->get(self::URL."?user={$u0001}");
        $this->assertSame('deny', $this->judgementOf($asU0001, 'http-services.authz.settings'));
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function judgementOf(TestResponse $response, string $code): string
    {
        return $this->row($response, $code)['judgements'][0]['result'];
    }
}
