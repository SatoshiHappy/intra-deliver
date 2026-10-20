<?php

namespace Tests\Support;

use App\Actions\Records\CreateModuleCategory;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuTranslation;

/**
 * モジュール分類の業務処理のテストの手助け(docs の 設計/業務データの入れ物/モジュール分類/): 分類を作る呼び出しと、「業務」の木の親の中の並び・
 * フォルダの表示名とアイコンの読み取り。
 */
trait ReadsModuleCategories
{
    private function createCategory(string $name): string
    {
        return app(CreateModuleCategory::class)->execute(['name' => $name]);
    }

    /**
     * 親(メニュー ID)の子の並び(表示の並び = sort_number・code の順。メニュー ID → sort_number)。親が無ければ空。
     *
     * @return array<string, int>
     */
    private function children(string $parentCode): array
    {
        $parent = Menu::query()->where('code', $parentCode)->first();

        return $parent === null ? [] : Menu::query()->where('parent_id', $parent->id)->orderBy('sort_number')->orderBy('code')->pluck('sort_number', 'code')->all();
    }

    /**
     * 2 つの木の、分類のフォルダの並び(グループ ID → [分類 ID → sort_number])。
     *
     * @return array<string, array<string, int>>
     */
    private function categoryOrder(): array
    {
        $order = [];

        foreach (WorkMenuGroup::cases() as $group) {
            $order[$group->value] = [];

            foreach ($this->children($group->value) as $code => $sortNumber) {
                $categoryId = $group->categoryIdOf($code);

                if ($categoryId !== null) {
                    $order[$group->value][$categoryId] = $sortNumber;
                }
            }
        }

        return $order;
    }

    /**
     * メニューの [表示名(ロケールのコード → 表示名。コードの順), icon_32]。無ければ null。
     *
     * @return array{array<string, string>, string|null}|null
     */
    private function display(string $code): ?array
    {
        $menu = Menu::query()->where('code', $code)->with('menuTranslations.locale')->first();

        return $menu === null ? null : [
            $menu->menuTranslations->mapWithKeys(fn (MenuTranslation $translation) => [(string) $translation->locale?->code => $translation->display_name])->sortKeys()->all(),
            $menu->icon_32,
        ];
    }

    /**
     * 業務モジュールのアイテムのメニュー ID(2 つの木の直下と分類の中。メニュー ID の順)。
     *
     * @return list<string>
     */
    private function placements(string $moduleCode): array
    {
        $codes = Menu::query()->where('code', 'like', '%-'.$moduleCode)->orderBy('code')->pluck('code')->all();

        return array_values(array_filter($codes, fn (string $code) => preg_match('/^(global-nav-main|sitemap-main)-(records|cat-[0-9a-z]{26})-'.preg_quote($moduleCode, '/').'$/D', $code) === 1));
    }
}
