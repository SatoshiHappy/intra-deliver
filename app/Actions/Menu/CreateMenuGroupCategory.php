<?php

namespace App\Actions\Menu;

use App\Actions\Authz\CreateResource;
use App\Actions\Master\WritesCommonMaster;
use App\Enums\Menu\MenuGroupAction;
use App\Enums\Menu\MenuResourceKind;
use App\Models\Menu\MenuGroupCategory;
use Illuminate\Validation\ValidationException;

/**
 * メニューグループカテゴリを作る(docs の 設計/メニュー/表.md 1.7 の 1)。カテゴリと表示名を作り、同じトランザクションでカテゴリの認可のリソースを作る
 * (認可との結び付け.md 2.2: リソースグループ im-menu-group-cat-<カテゴリ ID>、親はセット im-menu-group、URI im-menu-group://category/<カテゴリ ID>、
 * 表示名はカテゴリの表示名を写し、説明は NULL、並び順 0)。どちらかが失敗すれば両方が巻き戻る。認可の ID・URI は、読み直したカテゴリの
 * (保存された)コードから作る(長さを超えた部分が空白だけの値は DB が切り詰めて入れるので、入力の値で作ると表示判定・グループを作る業務処理が
 * 引く ID・URI と食い違う = docs の 設計/共通マスタ/共通の規則.md 5.5)。
 *
 * 止める規則: セット im-menu-group がある。リソースの規則は {@see CreateResource}。コードの一意・空文字は DB が止める。
 */
class CreateMenuGroupCategory
{
    use CreatesMenus;
    use WritesCommonMaster;

    public function __construct(
        private CreateResource $createResource,
    ) {}

    /**
     * @param  array<string, string>  $displayNames  ロケールのコード → 表示名
     *
     * @throws ValidationException 規則に反するとき、無いロケールのコードがあるとき
     */
    public function execute(string $code, array $displayNames, bool $isSelectable = false, bool $isEditable = false): MenuGroupCategory
    {
        $localeIds = $this->localeIds($displayNames);

        return $this->writeCommonMaster(function () use ($code, $displayNames, $isSelectable, $isEditable, $localeIds) {
            $category = MenuGroupCategory::query()->create(['code' => $code, 'is_selectable' => $isSelectable, 'is_editable' => $isEditable])->refresh();

            foreach ($displayNames as $localeCode => $displayName) {
                $category->menuGroupCategoryTranslations()->create(['locale_id' => $localeIds[$localeCode], 'display_name' => $displayName]);
            }

            $this->createResource->execute(
                MenuResourceKind::Category->resourceGroupCode($category->code),
                $this->resourceGroupId(MenuGroupAction::SET_CODE),
                MenuGroupAction::RESOURCE_TYPE,
                MenuResourceKind::Category->uri($category->code),
                $this->resourceTranslations($displayNames),
            );

            return $category;
        });
    }
}
