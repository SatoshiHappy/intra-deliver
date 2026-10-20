<?php

namespace App\Queries\Records;

use App\Enums\Menu\MenuType;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuTranslation;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * モジュール分類の読み取り(docs の 設計/業務データの入れ物/モジュール分類/README.md 1 節・2 節、業務処理.md 3.9)。
 * 分類と所属は global-nav-main の木だけで読み、サイトマップにだけ残ったフォルダを分類にしない(業務処理.md 3.1)。
 * 業務モジュールの判定をしない(管理のルートの認可だけ = 認可とメニュー/README.md 4 節)。
 *
 * @phpstan-type CategoryChoice array{id: string, name: string}
 */
class ModuleCategoryQuery
{
    /**
     * 「業務」のグループのルート(無ければ null)。
     */
    public function root(WorkMenuGroup $group): ?Menu
    {
        return Menu::query()->where('code', $group->value)->first();
    }

    /**
     * その木の分類のフォルダ(分類 ID → フォルダ。並びの順)。ルートが無ければ空。
     *
     * @return array<string, Menu>
     */
    public function folders(WorkMenuGroup $group): array
    {
        $root = $this->root($group);
        $folders = [];

        foreach ($root === null ? [] : Menu::query()->where('parent_id', $root->id)->where('type', MenuType::Folder)->orderBy('sort_number')->orderBy('code')->get() as $folder) {
            $categoryId = $group->categoryIdOf($folder->code);

            if ($categoryId !== null) {
                $folders[$categoryId] = $folder;
            }
        }

        return $folders;
    }

    /**
     * 分類(global-nav-main の分類のフォルダ)。分類 ID の形でないときも無いとする。
     *
     * @throws ModelNotFoundException<Menu> 分類が無いとき
     */
    public function find(string $categoryId): Menu
    {
        $folders = WorkMenuGroup::isCategoryId($categoryId) ? $this->folders(WorkMenuGroup::GlobalNavigation) : [];

        return $folders[$categoryId] ?? throw (new ModelNotFoundException)->setModel(Menu::class, [$categoryId]);
    }

    /**
     * すべての分類の分類 ID と名前(並びの順。業務モジュールの編集の props categories = 画面/管理の画面.md 3.5)。
     *
     * @return list<CategoryChoice>
     */
    public function choices(): array
    {
        $folders = $this->folders(WorkMenuGroup::GlobalNavigation);
        $names = $this->names(array_values($folders));

        return array_map(fn (string $categoryId, Menu $folder) => ['id' => $categoryId, 'name' => $names[$folder->id] ?? ''], array_keys($folders), $folders);
    }

    /**
     * 分類のフォルダの表示名(ロケールのコード → 表示名。コードの順)。サイトマップ側のフォルダを写しとして作り直すときに使う(業務処理.md 3.1)。
     *
     * @return array<string, string>
     */
    public function displayNames(Menu $folder): array
    {
        return MenuTranslation::query()->with('locale')->where('menu_id', $folder->id)->get()
            ->mapWithKeys(fn (MenuTranslation $translation) => [(string) $translation->locale?->code => $translation->display_name])
            ->sortKeys()->all();
    }

    /**
     * その木の、その親(分類 ID = 分類のフォルダ、null = 「業務」の直下)の中の業務モジュールのコード(アイテムの並びの順。直下は業務モジュールの
     * アイテム = <グループ ID>-records- の行だけで、初期のアイテムと分類のフォルダを含まない)。
     *
     * @return list<string>
     */
    public function moduleCodesIn(WorkMenuGroup $group, ?string $categoryId): array
    {
        $parentCode = $categoryId === null ? $group->value : $group->categoryFolderCode($categoryId);
        $prefix = $categoryId === null ? $group->recordModuleItemPrefix() : $group->categoryItemPrefix($categoryId);
        $parent = Menu::query()->where('code', $parentCode)->first();
        $codes = $parent === null ? [] : Menu::query()->where('parent_id', $parent->id)->orderBy('sort_number')->orderBy('code')->pluck('code')->all();

        return array_values(array_map(
            fn (string $code) => substr($code, strlen($prefix)),
            array_filter($codes, fn (string $code) => str_starts_with($code, $prefix)),
        ));
    }

    /**
     * global-nav-main の分類 ID → 中の業務モジュールのコード(分類の並びの順。中はアイテムの並びの順)。分類の数に依らず、
     * 分類のフォルダの子を 1 回の問い合わせで引く(モジュール分類の画面の props = 画面と検査.md 4.4)。
     *
     * @return array<string, list<string>>
     */
    public function moduleCodesByCategory(): array
    {
        $group = WorkMenuGroup::GlobalNavigation;
        $folders = $this->folders($group);
        $itemsByFolder = Menu::query()->whereIn('parent_id', array_map(fn (Menu $folder) => $folder->id, array_values($folders)))
            ->orderBy('sort_number')->orderBy('code')->get(['code', 'parent_id'])->groupBy('parent_id');
        $codes = [];

        foreach ($folders as $categoryId => $folder) {
            $prefix = $group->categoryItemPrefix($categoryId);
            $codes[$categoryId] = [];

            foreach ($itemsByFolder->get($folder->id, collect()) as $item) {
                if (str_starts_with($item->code, $prefix)) {
                    $codes[$categoryId][] = substr($item->code, strlen($prefix));
                }
            }
        }

        return $codes;
    }

    /**
     * 業務モジュールが属する分類 ID(分類の並びの順。README.md 1.4)。
     *
     * @return list<string>
     */
    public function categoryIdsOf(string $moduleCode): array
    {
        return $this->memberships([$moduleCode])[$moduleCode] ?? [];
    }

    /**
     * 渡した業務モジュールのコード → 属する分類 ID(分類の並びの順。どれにも属さないコードは含まない)。
     * 分類のフォルダ × 渡したコードのアイテムだけを引き、件数は分類の中のアイテムの総数に依らない。
     *
     * @param  list<string>  $moduleCodes
     * @return array<string, list<string>>
     */
    public function memberships(array $moduleCodes): array
    {
        $group = WorkMenuGroup::GlobalNavigation;
        $categoryIds = array_keys($this->folders($group));
        $targets = [];
        $owners = [];

        foreach ($moduleCodes as $moduleCode) {
            foreach ($categoryIds as $categoryId) {
                $itemCode = $group->categoryItemCode($categoryId, $moduleCode);
                $targets[$itemCode] = $group->categoryFolderCode($categoryId);
                $owners[$itemCode] = [$moduleCode, $categoryId];
            }
        }

        $memberships = [];

        foreach ($this->placedItemCodes($targets) as $itemCode) {
            [$moduleCode, $categoryId] = $owners[$itemCode];
            $memberships[$moduleCode][] = $categoryId;
        }

        return $memberships;
    }

    /**
     * 渡したアイテムのうち、目標の親の子にあるもののメニュー ID(渡した順)。業務モジュールのアイテムは目標の親(直下 = グループのルート、
     * 分類の中 = 分類のフォルダ)の子にあるときだけ「ある」と数える。表示中の判定とアイテムを揃える業務処理が同じ読み方をするための 1 か所
     * (認可とメニュー/メニューと初期データ.md 5.1、業務処理.md 3.7)。
     *
     * @param  array<string, string>  $parentCodes  アイテムのメニュー ID → 目標の親のメニュー ID
     * @return list<string>
     */
    public function placedItemCodes(array $parentCodes): array
    {
        if ($parentCodes === []) {
            return [];
        }

        $actualParentCodes = Menu::query()->join('menus as parents', 'parents.id', '=', 'menus.parent_id')
            ->whereIn('menus.code', array_keys($parentCodes))->toBase()->pluck('parents.code as parent_code', 'menus.code as item_code')->all();
        $placed = [];

        foreach ($parentCodes as $itemCode => $parentCode) {
            if (($actualParentCodes[$itemCode] ?? null) === $parentCode) {
                $placed[] = $itemCode;
            }
        }

        return $placed;
    }

    /**
     * 業務モジュールのアイテムのメニュー ID(2 つの木の直下と、すべての分類のフォルダの中。サイトマップにだけ残ったフォルダの中も含む。
     * あるものだけ、メニュー ID の順)。
     *
     * @return list<string>
     */
    public function itemCodes(string $moduleCode): array
    {
        $candidates = WorkMenuGroup::recordModuleItemCodes($moduleCode);

        foreach (WorkMenuGroup::cases() as $group) {
            foreach (array_keys($this->folders($group)) as $categoryId) {
                $candidates[] = $group->categoryItemCode($categoryId, $moduleCode);
            }
        }

        return array_values(Menu::query()->whereIn('code', $candidates)->orderBy('code')->get(['code'])->map(fn (Menu $item) => $item->code)->all());
    }

    /**
     * 分類のフォルダの id → 名前(分類のフォルダはすべてのロケールに同じ名前を持つので、ロケールのコードの順の先頭の表示名を読む)。
     *
     * @param  list<Menu>  $folders
     * @return array<int, string>
     */
    private function names(array $folders): array
    {
        $names = [];

        foreach (MenuTranslation::query()->with('locale')->whereIn('menu_id', array_map(fn (Menu $folder) => $folder->id, $folders))->get()
            ->sortBy(fn (MenuTranslation $translation) => (string) $translation->locale?->code) as $translation) {
            $names[$translation->menu_id] ??= $translation->display_name;
        }

        return $names;
    }
}
