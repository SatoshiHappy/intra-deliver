<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Master\User;
use App\Queries\Menu\Api\HomeSections;
use App\Queries\Workflow\WorkflowHomeSections;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FixedHomeSection;
use Tests\Support\TransactionalTestCase;

/**
 * ワークフローのホームの入口(docs の 設計/メニュー/ホーム.md 6.6 の「アプリのホームの入口」・6.7、節の中身と順 = 設計/ワークフロー/画面/
 * ホームと意匠とE2E.md 7 節): ページ Menu/Home の props today・sections(タグ WorkflowHomeSections::TAG の実装の null でない節を登録の順)・
 * app = {name: ワークフロー}。全体のホームのタグとワークフローのホームのタグは混ざらない。初期データ(ゲストへの許可)を入れ、
 * 今日を 2026-10-15(Asia/Tokyo)に固定し、ルートの認可を外さずに開く(デモはオフ)。
 *
 * @phpstan-import-type HomeLeadSection from HomeSections
 */
final class HomeControllerTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-14 15:30:00 UTC');
        $this->withoutVite();
    }

    /**
     * ゲストには案内の一文(workflow-lead)だけが出る。app の name はワークフローのアプリのグループの初期データの表示名と同じ。
     */
    public function test_a_guest_gets_only_the_lead_and_the_app_name(): void
    {
        $this->get(route('workflow.home'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Menu/Home')
            ->where('today', '2026-10-15')
            ->where('app', ['name' => 'ワークフロー'])
            ->where('sections', [$this->guestLead()])
            ->where('appNavigation.launcher.0.name', 'ワークフロー')
        );
    }

    /**
     * 利用者(u0001)には 5 つの節と主な操作が登録の順と 6.1 の置き場に出る。d に有効でない利用者(退職者 u0014)はゲストと同じ。
     */
    public function test_a_user_gets_the_five_sections_and_the_quick_actions_in_the_registration_order(): void
    {
        $this->actingAs($this->user('u0001'))->get('/workflow')->assertInertia(fn (Assert $page) => $page
            ->where('sections', fn (Collection $sections) => $sections->map(
                fn (array $section) => [$section['code'], $section['area']],
            )->all() === [
                ['workflow-lead', 'hero'],
                ['workflow-counters', 'top'],
                ['workflow-pending', 'main'],
                ['workflow-processed-this-month', 'main'],
                ['menu-quick-actions', 'side'],
                ['workflow-active-applications', 'side'],
            ])
        );

        $this->actingAs($this->user('u0014'))->get('/workflow')->assertInertia(fn (Assert $page) => $page
            ->where('account.user', null)
            ->where('sections', [$this->guestLead()])
        );
    }

    /**
     * 全体のホームのタグにテスト用の節を置いてもワークフローのホームに出ず、ワークフローのホームのタグに置いても全体のホームに出ない。
     */
    public function test_the_sections_of_the_two_homes_do_not_mix(): void
    {
        $global = $this->lead('test-global');
        $workflow = $this->lead('test-workflow');
        $this->register('test.home-sections.global', new FixedHomeSection($global), HomeSections::TAG);
        $this->register('test.home-sections.workflow', new FixedHomeSection($workflow), WorkflowHomeSections::TAG);

        $this->get('/')->assertInertia(fn (Assert $page) => $page->where('sections', [$global]));
        $this->get('/workflow')->assertInertia(fn (Assert $page) => $page
            ->where('sections', [$this->guestLead(), $workflow]));
    }

    /**
     * テストの中だけの実装を、タグに登録する(起動が登録したものの後に並ぶ)。
     */
    private function register(string $name, HomeSections $section, string $tag): void
    {
        $this->app->bind($name, fn () => $section);
        $this->app->tag([$name], $tag);
    }

    /**
     * @return HomeLeadSection
     */
    private function lead(string $code): array
    {
        return ['code' => $code, 'kind' => 'lead', 'area' => 'hero', 'text' => $code, 'action' => null];
    }

    /**
     * @return HomeLeadSection
     */
    private function guestLead(): array
    {
        $text = 'ゲストは申請も処理もできません。';

        return ['code' => 'workflow-lead', 'kind' => 'lead', 'area' => 'hero', 'text' => $text, 'action' => null];
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}
