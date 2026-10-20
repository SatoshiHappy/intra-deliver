<?php

namespace Tests\Feature\Models\Menu;

use App\Enums\Menu\MenuMethod;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * メニュー(docs の 設計/メニュー/表.md 1.4: 種別とメソッドは決まった値だけ、メソッドの既定は get、自分を親・コピー元にしない、
 * ソート番号は共通の規則 4 節のソートキーと同じ 15 桁で既定 0)。
 */
final class MenuTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    /**
     * @return array<string, array{MenuType}>
     */
    public static function types(): array
    {
        return array_combine(
            array_map(fn (MenuType $type) => $type->value, MenuType::cases()),
            array_map(fn (MenuType $type) => [$type], MenuType::cases()),
        );
    }

    #[DataProvider('types')]
    public function test_accepts_each_type_and_reads_it_as_the_enum(MenuType $type): void
    {
        $menu = Menu::factory()->create(['type' => $type]);

        $this->assertSame($type, $menu->refresh()->type);
    }

    public function test_rejects_a_type_outside_the_list(): void
    {
        $this->assertRejectedBy('menus_type_check', fn () => DB::table('menus')->insert([...$this->row(), 'type' => 'link']));
    }

    public function test_fills_get_when_the_method_is_omitted_and_reads_it_as_the_enum(): void
    {
        $id = DB::table('menus')->insertGetId($this->row());

        $this->assertSame(MenuMethod::Get, Menu::query()->findOrFail($id)->method);
    }

    public function test_accepts_post_and_rejects_a_method_outside_the_list(): void
    {
        $this->assertSame(MenuMethod::Post, Menu::factory()->create(['method' => MenuMethod::Post])->refresh()->method);
        $this->assertRejectedBy('menus_method_check', fn () => DB::table('menus')->insert([...$this->row(), 'method' => 'put']));
    }

    public function test_rejects_a_menu_as_its_own_parent_or_its_own_original(): void
    {
        $menu = Menu::factory()->create();

        $this->assertRejectedBy('menus_parent_id_id_check', fn () => $menu->update(['parent_id' => $menu->id]));
        $this->assertRejectedBy('menus_original_menu_id_id_check', fn () => $menu->update(['original_menu_id' => $menu->id]));
    }

    public function test_the_sort_number_is_within_15_digits_and_zero_by_default(): void
    {
        $id = DB::table('menus')->insertGetId($this->row());

        $this->assertSame(0, Menu::query()->findOrFail($id)->sort_number);
        $this->assertSame(999_999_999_999_999, Menu::factory()->create(['sort_number' => 999_999_999_999_999])->refresh()->sort_number);
        $this->assertRejectedBy('menus_sort_number_check', fn () => Menu::factory()->create(['sort_number' => -1_000_000_000_000_000]));
    }

    /**
     * 既定値のある列(ソート番号・メソッド・旗)を省いた行。
     *
     * @return array<string, string>
     */
    private function row(): array
    {
        return ['code' => fake()->unique()->bothify('menu####'), 'type' => MenuType::Folder->value];
    }
}
