<?php

namespace Tests\Feature\Http\Middleware\Menu;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\ServiceAction;
use App\Enums\Menu\MenuGroupAction;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 管理のアイテム「通知の管理」(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 4.1): 初期データでは
 * システム管理者にだけ出る。束「通知の管理」の実行とグループ「管理」の参照を許した一般の利用者には、歯車とサイトマップの管理に
 * このアイテムが(全員に開いた組織図の後に)出て、通知の一覧と通知の設定の今のアプリが管理になる。ルートの認可を外さずに開く。
 */
final class NoticeAdminItemTest extends TransactionalTestCase
{
    private const ITEM = 'global-nav-admin-notice-admin-show';

    /** 全員に開いた管理のアイテム「組織図」 */
    private const CHART = 'global-nav-admin-master-organization-chart-show';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
    }

    /**
     * 歯車の管理の末尾(代理の管理の後)に出る。ゲストと一般の利用者には歯車のアプリが無い。
     */
    public function test_the_seeded_data_shows_the_item_only_to_the_system_administrator(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('appNavigation.adminMenu', []));
        $this->actingAs($this->seededUser('u0001'))->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('appNavigation.adminMenu', []));
        $last = fn (Collection $items) => $items->pluck('code')->last() === self::ITEM;
        $this->actingAs($this->seededUser('u0004'))->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('appNavigation.adminMenu.0.children', $last));
    }

    /**
     * 束の実行と、歯車とサイトマップのグループ「管理」の参照を許した一般の利用者(ほかの管理のアイテムは、全員に開いた組織図だけが出る)。
     */
    public function test_a_general_user_permitted_the_bundle_and_the_admin_group_sees_this_item(): void
    {
        $user = $this->seededUser('u0001');
        $this->permitBundleAndReads($user, ['global-nav-admin', 'sitemap-admin']);

        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('appNavigation.adminMenu', self::codesAre(['global-nav-admin']))
            ->where('appNavigation.adminMenu.0.children', self::codesAre([self::CHART, self::ITEM]))
        );
        $this->get('/menu/sitemap')->assertInertia(fn (Assert $page) => $page
            ->where('groups.2.code', 'sitemap-admin')
            ->where('groups.2.children', self::codesAre([
                'sitemap-admin-master-organization-chart-show', 'sitemap-admin-notice-admin-show',
            ]))
        );
    }

    /**
     * 同じ許可の一般の利用者では、通知の一覧と通知の設定の今のアプリが管理(表示.md 3.3.2 の規則のまま)。
     */
    #[TestWith(['/notices'])]
    #[TestWith(['/notices/settings'])]
    public function test_the_notice_screens_belong_to_the_admin_app_for_a_permitted_general_user(string $path): void
    {
        $user = $this->seededUser('u0001');
        $this->permitBundleAndReads($user, ['global-nav-admin']);

        $this->actingAs($user)->get($path)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('appNavigation.currentApp', 'global-nav-admin')
        );
    }

    /**
     * 束「通知の管理」の実行と、メニューグループ(ID)の参照を、利用者 1 人に許す。
     *
     * @param  list<string>  $menuGroups
     */
    private function permitBundleAndReads(User $user, array $menuGroups): void
    {
        $subject = app(FindOrCreateSingleConditionSubjectGroup::class)
            ->execute(new SubjectConditionInput(userId: $user->id), [])->id;
        $grants = ['http-services.notice.admin' => [ServiceAction::RESOURCE_TYPE, ServiceAction::Execute->value]];

        foreach ($menuGroups as $menuGroup) {
            $grants["im-menu-group-{$menuGroup}"] = [MenuGroupAction::RESOURCE_TYPE, MenuGroupAction::Read->value];
        }

        foreach ($grants as $code => [$type, $action]) {
            $group = AuthzResourceGroup::query()->where('code', $code)->sole()->id;
            app(CreatePolicy::class)->execute($group, $subject, $type, $action, PolicyEffect::Permit);
        }
    }

    /**
     * 並びの code が渡した一覧と同じか(Inertia の where に渡す)。
     *
     * @param  list<string>  $codes
     * @return Closure(Collection<int, array{code: string}>): bool
     */
    private static function codesAre(array $codes): Closure
    {
        return fn (Collection $rows) => $rows->pluck('code')->all() === $codes;
    }

    private function seededUser(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}
