<?php

namespace Tests\Feature\Http\Middleware\Account;

use App\Models\Master\Department;
use App\Models\Master\User;
use App\Queries\Account\UserMenuSections;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\FixedUserMenuSection;
use Tests\Support\TransactionalTestCase;

/**
 * 今の利用者と利用者メニューの節の共有(docs の 設計/デモユーザー切替/今の利用者.md 5.7・5.9): 全ページの共有プロパティ account と
 * userMenuSections。account の currentDepartment と departments の id は組織の ID。節はタグ UserMenuSections::TAG に登録した実装を
 * 登録の順に呼んだ一覧で、main だけでは空。どちらも部分再読み込みの応答にも入る。
 * 初期データ(兼務の u0009 = 総務部が主所属、人事部を兼務)を入れ、今日を 2026-10-10 に固定し、ルートの認可を外さずに開く。
 */
final class ShareCurrentAccountTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->withoutVite();
    }

    #[TestWith(['/', 'Menu/Home'])]
    #[TestWith(['/menu/sitemap', 'Menu/Sitemap'])]
    public function test_shares_the_guest_and_no_user_menu_sections_with_every_page(string $path, string $component): void
    {
        $this->get($path)->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('name', config('app.name'))
            ->where('account', ['user' => null, 'currentDepartment' => null, 'departments' => []])
            ->where('userMenuSections', [])
        );
    }

    public function test_shares_the_user_the_selected_current_department_and_the_candidates_by_department_id(): void
    {
        $user = User::query()->where('code', 'u0009')->sole();
        $generalAffairs = $this->departmentId('general-affairs');
        $humanResources = $this->departmentId('human-resources');

        $response = $this->actingAs($user)->withSession([AccessContext::CURRENT_DEPARTMENT_SESSION_KEY => $humanResources])->get('/');

        $response->assertInertia(fn (Assert $page) => $page->where('account', [
            'user' => ['id' => $user->id, 'code' => 'u0009', 'name' => '吉田 達也'],
            'currentDepartment' => ['id' => $humanResources, 'code' => 'human-resources', 'name' => '人事部'],
            'departments' => [
                ['id' => $generalAffairs, 'code' => 'general-affairs', 'name' => '総務部', 'isMain' => true],
                ['id' => $humanResources, 'code' => 'human-resources', 'name' => '人事部', 'isMain' => false],
            ],
        ]));
    }

    public function test_the_current_department_is_the_main_membership_when_nothing_is_selected(): void
    {
        $user = User::query()->where('code', 'u0009')->sole();

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('account.currentDepartment', ['id' => $this->departmentId('general-affairs'), 'code' => 'general-affairs', 'name' => '総務部'])
        );
    }

    public function test_shares_the_sections_of_the_registered_implementations_in_the_order_of_registration(): void
    {
        $this->registerSections();
        $user = User::query()->where('code', 'u0001')->sole();

        $this->actingAs($user)->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('userMenuSections', $this->expectedSections("user {$user->id}"))
        );
    }

    public function test_shares_the_account_and_the_sections_also_on_a_partial_reload_of_other_props(): void
    {
        $this->registerSections();

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->reloadOnly('globalNavigation', fn (Assert $reload) => $reload
                ->where('account.user', null)
                ->where('userMenuSections', $this->expectedSections('guest'))
            )
        );
    }

    /**
     * テストの中だけの実装を 2 つ、後ろの見出しが先になる順でタグに登録する(並びが登録の順であって名前の順でないことを見るため)。
     */
    private function registerSections(): void
    {
        $this->app->bind('test.user-menu-sections.second', fn () => new FixedUserMenuSection('2 番目の節', 'second-item'));
        $this->app->bind('test.user-menu-sections.first', fn () => new FixedUserMenuSection('1 番目の節', 'first-item'));
        $this->app->tag(['test.user-menu-sections.second', 'test.user-menu-sections.first'], UserMenuSections::TAG);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expectedSections(string $note): array
    {
        return array_map(fn (array $section) => [
            'heading' => $section[0],
            'items' => [['code' => $section[1], 'name' => null, 'note' => $note, 'url' => '/', 'method' => 'get', 'data' => ['code' => $section[1]]]],
        ], [['2 番目の節', 'second-item'], ['1 番目の節', 'first-item']]);
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }
}
