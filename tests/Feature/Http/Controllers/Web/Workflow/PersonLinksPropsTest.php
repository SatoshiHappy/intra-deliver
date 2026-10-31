<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Authz\PolicyEffect;
use App\Models\Authz\AuthzPolicy;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Http\Controllers\Web\Authz\EditsAuthz;
use Tests\Support\TransactionalTestCase;

/**
 * 名前の札の「…」のリンクを出すかの props links(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.1・2.3): 札を出すページ(申請・申請一覧・
 * 申請の詳細・代理の設定・代理の管理)が、移り先のルートの execute を今日で判定した値を持つ。条件は 2 つの独立した判定で、表の行 = 2 × 2 の全部。
 * 合う設定に許可が 1 つでもあれば許可なので、許さない形はそのリソースグループの設定を消して作る。
 *
 * | 組織図(master.organization-chart)の execute | 認可設定(authz.overview)の execute | links |
 * |---|---|---|
 * | 許す | 許さない | 組織図だけ真(一般ユーザーの初期) |
 * | 許す | 許す | 両方が真(管理者・ポリシーを足した一般ユーザー) |
 * | 許さない | 許す | 認可設定だけ真 |
 * | 許さない | 許さない | 両方が偽 |
 *
 * 初期データを入れ、ルートの認可を外さずに開く。
 */
final class PersonLinksPropsTest extends TransactionalTestCase
{
    use AppliesOnTheBranchRoute;
    use EditsAuthz;
    use UsesSeededCases;

    private const ORGANIZATION_CHART = 'http-services.master.organization-chart';

    private const AUTHZ = 'http-services.authz.overview';

    private const ADMIN_PROXIES = 'http-services.workflow.admin.proxies';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * 一般ユーザー(u0001。代理の管理を開けるポリシーを足す)の 5 つのページ: 初期は組織図だけ。認可設定を許すポリシーを足すと両方、
     * さらに組織図を許さないと認可設定だけ。
     */
    public function test_a_general_user_gets_the_authz_link_by_an_added_policy(): void
    {
        $pages = $this->pagesOfTheGeneralUser();
        $this->actingAsUser('u0001');
        $this->assertLinks($pages, organizationChart: true, authz: false);

        $this->place(self::AUTHZ, $this->userColumn('u0001'), PolicyEffect::Permit);
        $this->assertLinks($pages, organizationChart: true, authz: true);

        $this->forbid(self::ORGANIZATION_CHART);
        $this->assertLinks($pages, organizationChart: false, authz: true);
    }

    /**
     * 組織図を許されない一般ユーザーは、どちらのリンクも出ない。
     */
    public function test_a_general_user_without_the_organization_chart_gets_no_link(): void
    {
        $pages = $this->pagesOfTheGeneralUser();
        $this->forbid(self::ORGANIZATION_CHART);
        $this->actingAsUser('u0001');

        $this->assertLinks($pages, organizationChart: false, authz: false);
    }

    /**
     * システム管理者(u0004)は初期データで両方のリンクを持つ。
     */
    public function test_the_administrator_gets_both_links(): void
    {
        $case = $this->applyOnBranch('u0001', 350);
        $pages = ['/workflow/admin/proxies', "/workflow/cases/{$case}", '/workflow/cases'];
        $this->actingAsUser('u0004');

        $this->assertLinks($pages, organizationChart: true, authz: true);
    }

    /**
     * 部分の再読み込み(links を求めない要求。申請一覧のタブ・代理の管理の絞り込み・申請の画面の読み直し)では links を返さない(遅れて評価する)。
     */
    public function test_a_partial_reload_does_not_carry_the_links(): void
    {
        $this->place(self::ADMIN_PROXIES, $this->userColumn('u0001'), PolicyEffect::Permit);
        $this->actingAsUser('u0001');
        $reloads = [
            '/workflow/cases' => 'cases',
            '/workflow/admin/proxies' => 'proxies',
            '/workflow/flows/project_plan/apply' => 'next',
        ];

        foreach ($reloads as $page => $prop) {
            $this->get($page)->assertOk()->assertInertia(fn (Assert $props) => $props
                ->has('links')
                ->reloadOnly($prop, fn (Assert $reload) => $reload->missing('links')));
        }
    }

    /**
     * 一般ユーザーが札を見るページ(申請・申請一覧・申請の詳細・代理の設定と、ポリシーを足して開ける代理の管理)。
     *
     * @return list<string>
     */
    private function pagesOfTheGeneralUser(): array
    {
        $case = $this->applyOnBranch('u0001', 350);
        $this->place(self::ADMIN_PROXIES, $this->userColumn('u0001'), PolicyEffect::Permit);

        return [
            '/workflow/flows/project_plan/apply',
            '/workflow/cases',
            "/workflow/cases/{$case}",
            '/workflow/proxies',
            '/workflow/admin/proxies',
        ];
    }

    /**
     * そのリソースグループの直接の設定を消す(だれにも許さない)。
     */
    private function forbid(string $groupCode): void
    {
        AuthzPolicy::query()->where('authz_resource_group_id', $this->groupId($groupCode))->delete();
    }

    /**
     * @param  list<string>  $pages
     */
    private function assertLinks(array $pages, bool $organizationChart, bool $authz): void
    {
        foreach ($pages as $page) {
            $this->get($page)->assertOk()->assertInertia(fn (Assert $props) => $props
                ->where('links', ['organizationChart' => $organizationChart, 'authz' => $authz]));
        }
    }
}
