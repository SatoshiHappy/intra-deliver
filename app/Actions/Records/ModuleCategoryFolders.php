<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Actions\Menu\AddMenu;
use App\Actions\Menu\MenuFolderInput;
use App\Actions\Menu\UpdateMenuSortNumbers;
use App\Enums\Menu\MenuIcon;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Menu\Menu;
use App\Queries\Records\ModuleCategoryQuery;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * モジュール分類の業務処理が共通に使う段(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.1、README.md 2 節):
 * - 分類 ID を受ける業務処理の入口({@see self::write()}): 共通マスタのロック → global-nav-main の分類のフォルダの引き直し(無ければ 404)→
 *   サイトマップ側のフォルダが無ければ写しとして作り直す(消す業務処理だけは飛ばす)。
 * - 欠けたサイトマップ側のフォルダの作り直し({@see self::restoreSitemapFolder()}。同じ表示名・icon_32 = folder。作り直したら両木の分類のフォルダを
 *   global-nav-main の順で付け直す。中のアイテムは戻さない)。
 * - 分類のフォルダの付け直し({@see self::renumber()}。木ごとに 10 + 位置。サイトマップにだけ残ったフォルダは数えない)。
 * 業務モジュールのロックは取らない(record_modules もレコードも書かない = 3.1)。
 */
class ModuleCategoryFolders
{
    use WritesCommonMaster;

    /** 分類のフォルダの並びの帯の始まり(10 + 位置 = README.md 2 節) */
    public const FIRST_SORT_NUMBER = 10;

    /** 分類の数の上限(帯 10〜99) */
    public const MAX_CATEGORIES = 90;

    public function __construct(
        private ModuleCategoryQuery $categories,
        private AddMenu $addMenu,
        private UpdateMenuSortNumbers $updateSortNumbers,
    ) {}

    /**
     * 分類 ID を受ける業務処理の共通の段の後で、callback に global-nav-main の分類のフォルダを渡す(callback は同じトランザクションの中)。
     *
     * @template TResult
     *
     * @param  Closure(Menu): TResult  $callback
     * @return TResult
     *
     * @throws ModelNotFoundException<Menu> 分類が無いとき
     * @throws ValidationException サイトマップの「業務」のグループが無いとき(作り直すとき。キー category)、callback が拒むとき
     */
    public function write(string $categoryId, bool $restoresSitemap, Closure $callback): mixed
    {
        return $this->writeCommonMaster(function () use ($categoryId, $restoresSitemap, $callback) {
            $folder = $this->categories->find($categoryId);

            if ($restoresSitemap) {
                $this->restoreSitemapFolder($categoryId, $folder);
            }

            return $callback($folder);
        });
    }

    /**
     * サイトマップ側の分類のフォルダが無ければ、global-nav-main のフォルダの写しとして作り直し、両木の分類のフォルダを global-nav-main の順で付け直す。
     *
     * @throws ValidationException サイトマップの「業務」のグループが無いとき(キー category)
     */
    public function restoreSitemapFolder(string $categoryId, Menu $globalFolder): void
    {
        $this->writeCommonMaster(function () use ($categoryId, $globalFolder) {
            $code = WorkMenuGroup::Sitemap->categoryFolderCode($categoryId);

            if (Menu::query()->where('code', $code)->exists()) {
                return;
            }

            $this->addMenu->execute($this->root(WorkMenuGroup::Sitemap)->id, new MenuFolderInput($code, $this->categories->displayNames($globalFolder), icon32: MenuIcon::Folder->value));
            $this->renumber(array_keys($this->categories->folders(WorkMenuGroup::GlobalNavigation)));
        });
    }

    /**
     * 「業務」のグループのルート。
     *
     * @throws ValidationException 無いとき(キー category。文言は業務モジュールの作成と同じ)
     */
    public function root(WorkMenuGroup $group): Menu
    {
        return $this->categories->root($group) ?? throw ValidationException::withMessages(['category' => ['メニューのグループ「業務」がありません']]);
    }

    /**
     * 木ごとに、分類のフォルダを 10 + 位置で付け直す。order = 両木に当てる分類 ID の並び(null = 木ごとの今の並び = sort_number・code の順)、
     * last = 末尾に回す分類 ID(作った分類)。global-nav-main に無い分類(サイトマップにだけ残ったフォルダ)は数えない。木に無いフォルダは飛ばす。
     *
     * @param  list<string>|null  $order
     *
     * @throws ValidationException メニューの業務処理が拒むとき(キー menu)
     */
    public function renumber(?array $order = null, ?string $last = null): void
    {
        $globalIds = array_keys($this->categories->folders(WorkMenuGroup::GlobalNavigation));

        foreach (WorkMenuGroup::cases() as $group) {
            $ids = $order ?? array_values(array_intersect(array_keys($this->categories->folders($group)), $globalIds));

            if ($last !== null) {
                $ids = [...array_values(array_diff($ids, [$last])), $last];
            }

            $sortNumbers = [];

            foreach ($ids as $position => $categoryId) {
                $sortNumbers[$group->categoryFolderCode($categoryId)] = self::FIRST_SORT_NUMBER + $position;
            }

            $this->updateSortNumbers->execute($sortNumbers);
        }
    }
}
