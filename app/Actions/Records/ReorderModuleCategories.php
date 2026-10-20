<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Menu\WorkMenuGroup;
use App\Queries\Records\ModuleCategoryQuery;
use Illuminate\Validation\ValidationException;

/**
 * モジュール分類を並べる(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.5、README.md 2 節)。入力 = { ids }(すべての分類 ID を新しい順に)。
 * 共通マスタのロックの後に引き直した global-nav-main の分類 ID の順列でなければ(過不足・重複・形の誤り・無い分類 ID)キー ids の 422 で何も書かない。
 * 欠けたサイトマップ側のフォルダを作り直してから({@see ModuleCategoryFolders::restoreSitemapFolder()})、2 つの木の分類のフォルダを ids の順に
 * 10 + 位置で付け直す。今と同じ順でも成功する。
 */
class ReorderModuleCategories
{
    use ChecksPermutation;
    use NormalizesInput;
    use WritesCommonMaster;

    public function __construct(
        private ModuleCategoryFolders $folders,
        private ModuleCategoryQuery $categories,
    ) {}

    /**
     * @param  array<array-key, mixed>  $ids  分類 ID の新しい順
     *
     * @throws ValidationException ids が今の分類 ID の順列でないとき(キー ids)、メニューの業務処理が拒むとき
     */
    public function execute(array $ids): void
    {
        $given = $this->normalizeInput($ids);

        $this->writeCommonMaster(function () use ($given) {
            $folders = $this->categories->folders(WorkMenuGroup::GlobalNavigation);
            $order = $this->permutation($given, array_keys($folders))
                ?? throw ValidationException::withMessages(['ids' => ['モジュール分類の並びが今のモジュール分類と一致しません。画面を再読み込みしてから再度操作してください']]);

            foreach ($folders as $categoryId => $folder) {
                $this->folders->restoreSitemapFolder($categoryId, $folder);
            }

            $this->folders->renumber($order);
        });
    }
}
