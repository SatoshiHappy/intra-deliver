<?php

namespace Tests\Feature\Http\Middleware\Menu;

use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 見出しの検索欄を出すかの共有(docs の 設計/共通マスタ/検索と組織図.md 3.1・3.2・5 節): 骨格の共有 appNavigation.showSearch は、組織図
 * (master.organization-chart)の execute を許されるか。ほかの骨格の値は {@see ShareAppNavigationTest}。許可はルートの束「共通マスタ」にあり、
 * 束の設定を消した(だれにも許さない)人では偽。値は人のリンクの組織図(links.organizationChart)と同じ。部分の再読み込みでも返す。
 * 偽の人でも共通マスタの検索の口は 200(業務の画面の人を選ぶ欄は変わらない)。初期データを入れ、今日を 2026-10-10 に固定し、ルートの認可を外さずに開く。
 */
final class ShareAppNavigationSearchTest extends TransactionalTestCase
{
    /** 組織図の許可を置くルートの束 */
    private const MASTER = 'http-services.master';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
    }

    #[TestWith([null])]
    #[TestWith(['u0001'])]
    #[TestWith(['u0004'])]
    public function test_shows_the_search_on_every_page_when_the_organization_chart_is_allowed(?string $userCode): void
    {
        if ($userCode !== null) {
            $this->actingAs($this->user($userCode));
        }

        foreach (['/', '/menu/sitemap'] as $path) {
            $this->get($path)->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where('appNavigation.showSearch', true));
        }
    }

    #[TestWith([null])]
    #[TestWith(['u0001'])]
    public function test_hides_the_search_when_the_bundle_of_the_common_master_is_closed(?string $userCode): void
    {
        $this->closeTheCommonMaster();

        if ($userCode !== null) {
            $this->actingAs($this->user($userCode));
        }

        $this->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('appNavigation.showSearch', false));
    }

    /**
     * 人のリンクの組織図と同じ値(許された人・閉じた後)。
     */
    public function test_the_value_is_the_same_as_the_person_link_to_the_organization_chart(): void
    {
        $this->actingAs($this->user('u0001'));

        foreach ([true, false] as $allowed) {
            if (! $allowed) {
                $this->closeTheCommonMaster();
            }

            $this->get('/workflow/cases')->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('appNavigation.showSearch', $allowed)
                ->where('links.organizationChart', $allowed));
        }
    }

    public function test_shares_the_value_also_on_a_partial_reload_of_other_props(): void
    {
        $this->actingAs($this->user('u0001'));

        $this->get('/workflow/cases')->assertInertia(function (Assert $page): void {
            $page->where('appNavigation.showSearch', true);
            $this->closeTheCommonMaster();
            $page->reloadOnly('cases', fn (Assert $reload) => $reload
                ->where('appNavigation.showSearch', false));
        });
    }

    /**
     * 組織図を閉じた人でも、業務の画面の人を選ぶ欄が使う共通マスタの検索の口は 200。
     */
    public function test_the_search_endpoint_stays_open_without_the_organization_chart(): void
    {
        $this->closeTheCommonMaster();
        $this->actingAs($this->user('u0001'));

        $this->getJson('/master/search?q=u0001')->assertOk()->assertJsonPath('users.0.code', 'u0001');
    }

    private function closeTheCommonMaster(): void
    {
        $groupId = AuthzResourceGroup::query()->where('code', self::MASTER)->sole()->id;
        AuthzPolicy::query()->where('authz_resource_group_id', $groupId)->delete();
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}
