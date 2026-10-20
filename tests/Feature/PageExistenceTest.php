<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Inertia\ComponentNotFoundException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * ページの実在の検査(config/inertia.php の pages.ensure_pages_exist と pages.extensions。docs の 設計/コード構成/README.md 5 節)の自己証明。
 *
 * PHPUnit の環境では、入口が描くページのファイルが無いと描画が失敗する(テストの component() の呼び出しに頼らない)。
 * ページの置き場(pages.paths)だけを fixture(tests/Architecture/fixtures/PageExistence/pages)に差し替え、
 * 実在の確かめの有無と拡張子は設定の実物のまま描画する。
 */
final class PageExistenceTest extends TransactionalTestCase
{
    private const PROBE_URI = '/__page-existence-probe';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['inertia.pages.paths' => [base_path('tests/Architecture/fixtures/PageExistence/pages')]]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unresolvablePages(): array
    {
        return [
            'ファイルが無いページ' => ['Alpha/Missing'],
            '.tsx でないファイルのページ' => ['Alpha/Script'],
        ];
    }

    #[DataProvider('unresolvablePages')]
    public function test_rendering_a_page_without_a_tsx_file_fails(string $component): void
    {
        Route::inertia(self::PROBE_URI, $component);
        $this->withoutExceptionHandling();

        $this->expectException(ComponentNotFoundException::class);

        $this->get(self::PROBE_URI);
    }

    public function test_renders_a_page_whose_tsx_file_exists(): void
    {
        Route::inertia(self::PROBE_URI, 'Alpha/Page');

        $this->get(self::PROBE_URI)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Alpha/Page'));
    }
}
