<?php

namespace Tests\Feature\Http\Controllers\Web\Menu;

use App\Models\Master\User;
use App\Queries\Menu\Api\HomeSections;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Support\FixedHomeSection;
use Tests\Support\ThrowingHomeSection;
use Tests\Support\TransactionalTestCase;

/**
 * 全体のホームの入口(docs の 設計/メニュー/ホーム.md 6.1・6.2・6.6): ページ Menu/Home の props today に基準日 d(YYYY-MM-DD)、sections に
 * タグ HomeSections::TAG で登録した実装の null でない節を登録の順に渡し、app は null。節が無ければ空。実装が例外を投げればホームは 500
 * (実装は投げない契約 = 設計/ワークフロー/業務データとの接続/公開の口.md 8.1)。
 * 初期データ(ゲストへの許可)を入れ、今日を 2026-10-15(Asia/Tokyo)に固定し、ルートの認可を外さずに開く。
 *
 * @phpstan-import-type HomeTilesSection from HomeSections
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
     * today は Asia/Tokyo の暦の日付(UTC では前の日の 15:30 = 東京の 0:30)。
     */
    public function test_passes_the_date_of_the_request_as_today_and_no_app(): void
    {
        $this->get(route('menu.home'))->assertInertia(fn (Assert $page) => $page
            ->component('Menu/Home')
            ->where('today', '2026-10-15')
            ->where('app', null)
        );
    }

    /**
     * 起動が登録した節はゲストに出ない(デモはオフ)。
     */
    public function test_a_guest_gets_no_sections(): void
    {
        $this->get('/')->assertInertia(fn (Assert $page) => $page->component('Menu/Home')->where('sections', []));
    }

    /**
     * ワークフローの節と主な操作(workflow- で始まる code と menu-quick-actions)は全体のホームに出ない(ワークフローのホームのタグに登録する =
     * 6.7。デモはオフなので全体のホームの節は無い)。d に有効でない利用者(退職者 u0014)でログインした要求はゲストと同じホーム(account.user が null = あいさつ欄の見出しが「ようこそ」)
     * になる(6.1 のゲストの定義)。
     */
    public function test_a_user_gets_no_workflow_section_and_a_retiree_gets_the_guest_home(): void
    {
        $this->actingAs($this->user('u0001'))->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('account.user.code', 'u0001')
            ->where('sections', [])
        );

        $this->actingAs($this->user('u0014'))->get('/')->assertInertia(fn (Assert $page) => $page
            ->where('account.user', null)
            ->where('sections', [])
        );
    }

    public function test_passes_the_sections_in_the_registration_order_and_leaves_out_null(): void
    {
        $second = $this->section('second', '2 番目の節');
        $first = $this->section('first', '1 番目の節');
        $this->register([
            'test.home-sections.second' => new FixedHomeSection($second),
            'test.home-sections.hidden' => new FixedHomeSection(null),
            'test.home-sections.first' => new FixedHomeSection($first),
        ]);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Menu/Home')
            ->where('sections', [$second, $first])
        );
    }

    public function test_an_implementation_that_throws_makes_the_home_fail(): void
    {
        $this->register([
            'test.home-sections.fixed' => new FixedHomeSection($this->section('fixed', '節')),
            'test.home-sections.throwing' => new ThrowingHomeSection,
        ]);

        $this->get('/')->assertInternalServerError();

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ホームの節を作れなかった');
        $this->get('/');
    }

    /**
     * テストの中だけの実装を、渡した順でタグに登録する(起動が登録したものの後に並ぶ)。
     *
     * @param  array<string, HomeSections>  $sections  コンテナの名前 → 実装
     */
    private function register(array $sections): void
    {
        foreach ($sections as $name => $section) {
            $this->app->bind($name, fn () => $section);
        }

        $this->app->tag(array_keys($sections), HomeSections::TAG);
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }

    /**
     * @return HomeTilesSection
     */
    private function section(string $code, string $heading): array
    {
        return [
            'code' => $code,
            'kind' => 'tiles',
            'area' => 'side',
            'heading' => $heading,
            'items' => [
                ['code' => "{$code}-sitemap", 'label' => 'サイトマップ', 'icon' => 'map', 'url' => '/menu/sitemap', 'isExternal' => false],
                ['code' => "{$code}-external", 'label' => '外部', 'icon' => null, 'url' => 'https://example.com/', 'isExternal' => true],
            ],
        ];
    }
}
