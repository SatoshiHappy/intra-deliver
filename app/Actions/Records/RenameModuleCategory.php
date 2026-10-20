<?php

namespace App\Actions\Records;

use App\Actions\Master\FillsAllLocales;
use App\Actions\Menu\UpdateMenuDisplay;
use App\Enums\Menu\MenuIcon;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Menu\Menu;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * モジュール分類の名前を変える(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.3)。入力 = 分類 ID と { name }。
 * 共通の段({@see ModuleCategoryFolders::write()}: 分類が無ければ 404、欠けたサイトマップ側のフォルダは作り直す)の後で名前を確かめ(キー name)、
 * 2 つのフォルダの表示名(すべてのロケール)を置き換える(icon_32 は folder のまま)。
 */
class RenameModuleCategory
{
    use FillsAllLocales;
    use NormalizesInput;
    use ValidatesDefinitionText;

    public function __construct(
        private ModuleCategoryFolders $folders,
        private UpdateMenuDisplay $updateMenuDisplay,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ModelNotFoundException<Menu> 分類が無いとき
     * @throws ValidationException 規則に反するとき
     */
    public function execute(string $categoryId, array $input): void
    {
        $input = $this->normalizeInput($input);

        $this->folders->write($categoryId, true, function () use ($categoryId, $input) {
            $name = $input['name'] ?? null;
            $this->throwIfAny(['name' => $this->textError($name, '名前')]);
            $names = $this->forAllLocales((string) $name);
            $this->updateMenuDisplay->execute(
                array_map(fn (WorkMenuGroup $group) => $group->categoryFolderCode($categoryId), WorkMenuGroup::cases()),
                $names,
                MenuIcon::Folder->value,
            );
        });
    }
}
