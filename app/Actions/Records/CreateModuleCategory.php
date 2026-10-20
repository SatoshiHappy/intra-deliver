<?php

namespace App\Actions\Records;

use App\Actions\Master\FillsAllLocales;
use App\Actions\Master\WritesCommonMaster;
use App\Actions\Menu\AddMenu;
use App\Actions\Menu\MenuFolderInput;
use App\Enums\Menu\MenuIcon;
use App\Enums\Menu\WorkMenuGroup;
use App\Queries\Records\ModuleCategoryQuery;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * モジュール分類を作る(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.2、README.md 1 節・2 節)。入力 = { name }。
 * 分類 ID(小文字の ULID)を作り、「業務」の 2 つのグループ({@see WorkMenuGroup})のルートの直下に分類のフォルダ(メニュー ID <グループ ID>-cat-<分類 ID>、
 * 表示名 = すべてのロケールに名前、icon_32 = folder)を足し、木ごとにすべての分類のフォルダを、新しい分類を末尾にして 10 + 位置で付け直す。
 * 拒否: 名前(前後の空白を除く・空でない・1,000 文字まで・U+0000 なし。キー name)、分類がすでに 90(キー category)、「業務」のグループが無い(キー category)。
 * 共通マスタの勧告ロックの下の 1 つのトランザクション。返り値 = 分類 ID。
 */
class CreateModuleCategory
{
    use FillsAllLocales;
    use NormalizesInput;
    use ValidatesDefinitionText;
    use WritesCommonMaster;

    public function __construct(
        private ModuleCategoryFolders $folders,
        private ModuleCategoryQuery $categories,
        private AddMenu $addMenu,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException 規則に反するとき
     */
    public function execute(array $input): string
    {
        $input = $this->normalizeInput($input);

        return $this->writeCommonMaster(function () use ($input) {
            $name = $input['name'] ?? null;
            $this->throwIfAny([
                'name' => $this->textError($name, '名前'),
                'category' => count($this->categories->folders(WorkMenuGroup::GlobalNavigation)) >= ModuleCategoryFolders::MAX_CATEGORIES
                    ? 'モジュール分類は '.ModuleCategoryFolders::MAX_CATEGORIES.' までです'
                    : null,
            ]);
            $roots = array_map(fn (WorkMenuGroup $group) => $this->folders->root($group), WorkMenuGroup::cases());
            $categoryId = Str::lower((string) Str::ulid());
            $names = $this->forAllLocales((string) $name);

            foreach (WorkMenuGroup::cases() as $index => $group) {
                $this->addMenu->execute($roots[$index]->id, new MenuFolderInput($group->categoryFolderCode($categoryId), $names, icon32: MenuIcon::Folder->value));
            }

            $this->folders->renumber(last: $categoryId);

            return $categoryId;
        });
    }
}
