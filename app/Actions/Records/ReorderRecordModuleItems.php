<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Actions\Menu\UpdateMenuSortNumbers;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Menu\Menu;
use App\Queries\Records\ModuleCategoryQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 分類の中か「業務」の直下の業務モジュールを並べる(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.6、README.md 2 節)。
 * 入力 = 親(分類 ID か null = 「業務」の直下)と { codes }(その親の中の業務モジュールのコードを新しい順に)。
 * 親が分類なら共通の段({@see ModuleCategoryFolders::write()}: 無ければ 404、欠けたサイトマップ側のフォルダは作り直す)を通る。
 * codes が global-nav-main のその親の中の業務モジュールのコード(直下なら <グループ ID>-records- の行だけ)の順列でなければ(過不足・重複・非表示の
 * 業務モジュールのコード)キー codes の 422 で何も書かない。2 つの木のその親の中の業務モジュールのアイテムを codes の順に 100 + 10 × 位置で付け直す
 * (木に無いアイテムは飛ばし、サイトマップにだけあるアイテムは触らない。直下の分類のフォルダは帯が別なので動かさない)。今と同じ順でも成功する。
 */
class ReorderRecordModuleItems
{
    use ChecksPermutation;
    use NormalizesInput;
    use WritesCommonMaster;

    public function __construct(
        private ModuleCategoryFolders $folders,
        private ModuleCategoryQuery $categories,
        private UpdateMenuSortNumbers $updateSortNumbers,
    ) {}

    /**
     * @param  array<array-key, mixed>  $codes  業務モジュールのコードの新しい順
     *
     * @throws ModelNotFoundException<Menu> 親の分類が無いとき
     * @throws ValidationException codes がその親の中の業務モジュールのコードの順列でないとき(キー codes)、メニューの業務処理が拒むとき
     */
    public function execute(?string $categoryId, array $codes): void
    {
        $given = $this->normalizeInput($codes);

        if ($categoryId === null) {
            $this->writeCommonMaster(fn () => $this->reorder(null, $given));

            return;
        }

        $this->folders->write($categoryId, true, fn () => $this->reorder($categoryId, $given));
    }

    /**
     * @param  array<array-key, mixed>  $given
     *
     * @throws ValidationException 順列でないとき(キー codes)
     */
    private function reorder(?string $categoryId, array $given): void
    {
        $order = $this->permutation($given, $this->categories->moduleCodesIn(WorkMenuGroup::GlobalNavigation, $categoryId))
            ?? throw ValidationException::withMessages(['codes' => ['業務モジュールの並びが今の並びと一致しません。画面を再読み込みしてから再度操作してください']]);

        foreach (WorkMenuGroup::cases() as $group) {
            $sortNumbers = [];

            foreach ($order as $position => $moduleCode) {
                $itemCode = $categoryId === null ? $group->recordModuleItemCode($moduleCode) : $group->categoryItemCode($categoryId, $moduleCode);
                $sortNumbers[$itemCode] = AddRecordModuleItems::FIRST_SORT_NUMBER + AddRecordModuleItems::SORT_STEP * $position;
            }

            $this->updateSortNumbers->execute($sortNumbers);
        }
    }
}
