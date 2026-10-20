<?php

namespace Tests\Feature\Actions\Menu;

use App\Actions\Menu\UpdateMenuSortNumbers;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * メニューのソート番号を変える(docs の 設計/メニュー/表.md 1.7 の 6、業務データの入れ物/モジュール分類/業務処理.md 3.8): メニュー ID → ソート番号のうち、
 * あるものだけを変え(無いものは飛ばす)、変えたメニュー ID を渡された順に返す。ほかの列は変えない。外部メニューリンク・範囲の外・整数でない値が
 * 1 つでもあればキー menu で拒み、何も書かない。
 */
final class UpdateMenuSortNumbersTest extends TransactionalTestCase
{
    use CatchesValidationErrors;

    public function test_changes_only_the_existing_folders_and_items_and_returns_them_in_the_given_order(): void
    {
        $folder = Menu::factory()->create(['code' => 'example-folder', 'sort_number' => 5, 'icon_32' => 'folder']);
        $item = Menu::factory()->item()->create(['code' => 'example-item', 'sort_number' => 7]);

        $changed = app(UpdateMenuSortNumbers::class)->execute(['example-item' => 20, 'example-nothing' => 30, 'example-folder' => -999_999_999_999_999]);

        $this->assertSame(['example-item', 'example-folder'], $changed);
        $this->assertSame([-999_999_999_999_999, 'folder', MenuType::Folder], [$folder->fresh()?->sort_number, $folder->fresh()?->icon_32, $folder->fresh()?->type]);
        $this->assertSame(20, $item->fresh()?->sort_number);
        $this->assertSame([], app(UpdateMenuSortNumbers::class)->execute(['example-nothing' => 1]));
    }

    #[TestWith([1_000_000_000_000_000, 'ソート番号は 15 桁までの整数にしてください: example-item'])]
    #[TestWith([-1_000_000_000_000_000, 'ソート番号は 15 桁までの整数にしてください: example-item'])]
    #[TestWith(['10', 'ソート番号は 15 桁までの整数にしてください: example-item'])]
    public function test_rejects_a_value_out_of_the_range_and_changes_nothing(mixed $sortNumber, string $message): void
    {
        $folder = Menu::factory()->create(['code' => 'example-folder', 'sort_number' => 5]);
        Menu::factory()->item()->create(['code' => 'example-item', 'sort_number' => 7]);

        $errors = $this->validationErrors(fn () => app(UpdateMenuSortNumbers::class)->execute(['example-folder' => 10, 'example-item' => $sortNumber]));

        $this->assertSame(['menu' => $message], $errors);
        $this->assertSame(5, $folder->fresh()?->sort_number);
    }

    public function test_rejects_an_external_link_and_changes_nothing(): void
    {
        $folder = Menu::factory()->create(['code' => 'example-folder', 'sort_number' => 5]);
        Menu::factory()->create(['code' => 'example-external', 'type' => MenuType::External, 'url' => 'https://example.com/', 'sort_number' => 3]);

        $errors = $this->validationErrors(fn () => app(UpdateMenuSortNumbers::class)->execute(['example-folder' => 10, 'example-external' => 20]));

        $this->assertSame(['menu' => 'ソート番号を変更できるのはメニューフォルダかメニューアイテムだけです: example-external'], $errors);
        $this->assertSame(['example-external' => 3, 'example-folder' => 5], Menu::query()->whereIn('code', ['example-folder', 'example-external'])->orderBy('code')->pluck('sort_number', 'code')->all());
    }
}
