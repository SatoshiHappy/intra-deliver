<?php

namespace App\Actions\Records;

use App\Actions\Menu\RemoveMenu;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Menu\Menu;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * モジュール分類を消す(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.4)。入力 = 分類 ID。共通の段({@see ModuleCategoryFolders::write()}:
 * 分類が無ければ 404。消す分類のサイトマップ側のフォルダは作り直さず、無ければその木を飛ばす)の後で:
 * 1. 中の業務モジュール = 2 つのフォルダの中のアイテムのメニュー ID から読んだコード(global-nav-main の中の並び、次にサイトマップだけにあるものをコードの順)。
 * 2. その業務モジュールごとに配置を揃える({@see SyncRecordModuleItems})。目標 = 表示中なら「今の分類 − この分類」(空なら直下 = 末尾に作られる。Q3)、
 *    表示中でなければどこにも置かない(はぐれたアイテムは消えるだけ)。record_modules に無いコード(親の無い業務モジュールでないもの)のアイテムは消すだけ。
 *    1・2 は分類の中を揃える業務処理の codes = [] と同じ形なので、{@see SetModuleCategoryModules::placeMembers()} を使う(業務処理.md 3.10)。
 * 3. 空になった 2 つのフォルダを消す({@see RemoveMenu})。 4. 残る分類のフォルダを木ごとに付け直す。
 * 下位の業務処理の拒否はキー category(文言は下位のもの)で、全体が巻き戻る。返り値なし。
 */
class DeleteModuleCategory
{
    public function __construct(
        private ModuleCategoryFolders $folders,
        private SetModuleCategoryModules $setModules,
        private RemoveMenu $removeMenu,
    ) {}

    /**
     * @throws ModelNotFoundException<Menu> 分類が無いとき
     * @throws ValidationException 下位の業務処理が拒むとき(キー category)
     */
    public function execute(string $categoryId): void
    {
        $this->folders->write($categoryId, false, function () use ($categoryId) {
            try {
                $this->setModules->placeMembers($categoryId, []);
                $this->removeMenu->execute(array_map(fn (WorkMenuGroup $group) => $group->categoryFolderCode($categoryId), WorkMenuGroup::cases()));
                $this->folders->renumber();
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['category' => array_merge(...array_values($exception->errors()))]);
            }
        });
    }
}
