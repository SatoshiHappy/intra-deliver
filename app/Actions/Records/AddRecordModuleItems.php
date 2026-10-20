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
 * 親の無い業務モジュールの「業務」の直下のアイテムを、2 つのグループのうち無いものにだけ作る(docs の 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 5.1)。
 * 業務モジュールを作る業務処理と表示に戻す業務処理が同じ規則で作るための 1 か所。「ある」は表示中の判定と同じ読み方
 * ({@see ModuleCategoryQuery::placedItemCodes()})で、ルートの子でない同じメニュー ID のアイテムは消してから作る(メニュー ID は一意なので残すと作れない)。
 */
class AddRecordModuleItems
{
    use WritesCommonMaster;

    /** 最初の業務モジュールのアイテムの並び(初期のアイテム(0〜2)の後) */
    public const FIRST_SORT_NUMBER = 100;

    /** 業務モジュールのアイテムの並びの間隔(作った順に、そのグループの最大 + この値) */
    public const SORT_STEP = 10;

    public function __construct(
        private ModuleCategoryQuery $categories,
        private AddMenu $addMenu,
        private RemoveMenu $removeMenu,
    ) {}

    /**
     * @param  array<string, string>  $displayNames  ロケールのコード → 表示名
     * @return list<string> 作ったメニュー ID(グループの宣言の順。あったものは含まない)
     *
     * @throws ValidationException 「業務」のグループが無いとき(キー module)、メニューを足す・消す業務処理が拒むとき
     */
    public function execute(RecordModule $module, array $displayNames): array
    {
        return $this->writeCommonMaster(function () use ($module, $displayNames) {
            $groupCodes = array_map(fn (WorkMenuGroup $group) => $group->value, WorkMenuGroup::cases());
            $groups = Menu::query()->whereIn('code', $groupCodes)->get()->keyBy('code')->all();

            if (count($groups) !== count(WorkMenuGroup::cases())) {
                throw ValidationException::withMessages(['module' => ['メニューのグループ「業務」がありません']]);
            }

            $placed = $this->categories->placedItemCodes(array_combine(WorkMenuGroup::recordModuleItemCodes($module->code), $groupCodes));
            $added = [];

            foreach (WorkMenuGroup::cases() as $group) {
                $itemCode = $group->recordModuleItemCode($module->code);

                if (in_array($itemCode, $placed, true)) {
                    continue;
                }

                $parent = $groups[$group->value];
                $this->removeMenu->execute([$itemCode]);
                $this->addMenu->execute($parent->id, new MenuItemInput($itemCode, "/records/data/{$module->code}", $displayNames, sortNumber: self::nextSortNumber($parent->id, $group->recordModuleItemPrefix()), icon32: $module->icon));
                $added[] = $itemCode;
            }

            return $added;
        });
    }

    /**
     * 親(「業務」のルートか分類のフォルダ)の中の、次の業務モジュールのアイテムの並び(認可とメニュー/メニューと初期データ.md 5.1、モジュール分類/README.md 2 節)。
     * LIKE の _ を避けて、メニュー ID の接頭辞は PHP で絞る。{@see SyncRecordModuleItems} も分類の中のアイテムに使う。
     */
    public static function nextSortNumber(int $parentId, string $prefix): int
    {
        $current = Menu::query()->where('parent_id', $parentId)->get(['code', 'sort_number'])
            ->filter(fn (Menu $item) => str_starts_with($item->code, $prefix))
            ->max('sort_number');

        return is_numeric($current) ? (int) $current + self::SORT_STEP : self::FIRST_SORT_NUMBER;
    }
}
