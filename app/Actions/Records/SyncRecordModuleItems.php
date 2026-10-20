<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Actions\Menu\AddMenu;
use App\Actions\Menu\MenuItemInput;
use App\Actions\Menu\RemoveMenu;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Menu\Menu;
use App\Models\Records\RecordModule;
use App\Queries\Records\ModuleCategoryQuery;
use Illuminate\Validation\ValidationException;

/**
 * 親の無い業務モジュールの「業務」のアイテムの配置を目標に揃える(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.7、README.md 1.4・2 節)。
 * 今の状態とは比べず、片方の木だけがある状態からも 1 回で揃える。「ある」は表示中の判定と同じ読み方({@see ModuleCategoryQuery::placedItemCodes()})。
 * 目標の分類があることは呼び手が確かめる。
 */
class SyncRecordModuleItems
{
    use WritesCommonMaster;

    public function __construct(
        private ModuleCategoryQuery $categories,
        private ModuleCategoryFolders $folders,
        private AddRecordModuleItems $addItems,
        private AddMenu $addMenu,
        private RemoveMenu $removeMenu,
    ) {}

    /**
     * @param  list<string>|null  $categoryIds  目標(null = どこにも置かない、[] = 直下、空でない = その分類ごと)
     * @param  array<string, string>  $displayNames  ロケールのコード → 表示名(作るアイテムの表示名)
     * @return array{created: list<string>, removed: list<string>} 作ったメニュー ID と消したメニュー ID
     *
     * @throws ValidationException 「業務」のグループが無いとき、メニューの業務処理が拒むとき
     */
    public function execute(RecordModule $module, ?array $categoryIds, array $displayNames): array
    {
        return $this->writeCommonMaster(function () use ($module, $categoryIds, $displayNames) {
            [$created, $targets] = match (true) {
                $categoryIds === null => [[], []],
                $categoryIds === [] => [$this->addItems->execute($module, $displayNames), WorkMenuGroup::recordModuleItemCodes($module->code)],
                default => $this->placeInCategories($module, $categoryIds, $displayNames),
            };
            $removed = $this->removeMenu->execute(array_values(array_diff($this->categories->itemCodes($module->code), $targets)));

            return ['created' => $created, 'removed' => $removed];
        });
    }

    /**
     * 分類ごとに、2 つの木の分類のフォルダの子に無いアイテムを作る。フォルダの外にある同じメニュー ID のアイテムは消してから作る
     * (メニュー ID は一意なので残すと作れない)。
     *
     * @param  list<string>  $categoryIds
     * @param  array<string, string>  $displayNames
     * @return array{list<string>, list<string>} [作ったメニュー ID, 目標のメニュー ID]
     */
    private function placeInCategories(RecordModule $module, array $categoryIds, array $displayNames): array
    {
        $parents = [];

        foreach ($categoryIds as $categoryId) {
            $this->folders->restoreSitemapFolder($categoryId, $this->categories->find($categoryId));

            foreach (WorkMenuGroup::cases() as $group) {
                $parents[$group->categoryItemCode($categoryId, $module->code)] = [$group->categoryFolderCode($categoryId), $group->categoryItemPrefix($categoryId)];
            }
        }

        $placed = array_flip($this->categories->placedItemCodes(array_map(fn (array $parent) => $parent[0], $parents)));
        $created = [];

        foreach (array_diff_key($parents, $placed) as $itemCode => [$folderCode, $prefix]) {
            $folder = Menu::query()->where('code', $folderCode)->sole();
            $this->removeMenu->execute([$itemCode]);
            $this->addMenu->execute($folder->id, new MenuItemInput($itemCode, "/records/data/{$module->code}", $displayNames, sortNumber: AddRecordModuleItems::nextSortNumber($folder->id, $prefix), icon32: $module->icon));
            $created[] = $itemCode;
        }

        return [$created, array_keys($parents)];
    }
}
