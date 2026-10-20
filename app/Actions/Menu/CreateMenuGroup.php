<?php

namespace App\Actions\Menu;

use App\Actions\Authz\CreateResource;
use App\Actions\Master\WritesCommonMaster;
use App\Enums\Menu\MenuGroupAction;
use App\Enums\Menu\MenuResourceKind;
use App\Models\Menu\MenuGroup;
use App\Models\Menu\MenuGroupCategory;
use Illuminate\Validation\ValidationException;

/**
 * メニューグループを作る(docs の 設計/メニュー/表.md 1.7 の 2)。ルートのメニュー(種別 folder、親なし、メニュー ID = グループ ID)・ルートの表示名・
 * グループ・カテゴリへの所属を作り、同じトランザクションでグループの認可のリソースを作る(認可との結び付け.md 2.2: リソースグループ
 * im-menu-group-<グループ ID>、親は作る先のカテゴリのリソースグループ、URI im-menu-group://group/<グループ ID>、表示名はルートの表示名を写し、
 * 説明は NULL、並び順 0)。どれかが失敗すればすべて巻き戻る。認可の ID・URI は、読み直したルートのメニューの(保存された)グループ ID から作る
 * (長さを超えた部分が空白だけの値は DB が切り詰めて入れるので、入力の値で作ると表示判定が引く ID・URI と食い違う = docs の 設計/共通マスタ/共通の規則.md 5.5)。
 *
 * 止める規則: グループのルートは folder に限る(入力の型で決まる)。作る先のカテゴリと、そのリソースグループがある。リソースの規則は {@see CreateResource}。
 * グループ ID(メニュー ID)の一意は DB が止める。
 */
class CreateMenuGroup
{
    use CreatesMenus;
    use WritesCommonMaster;

    public function __construct(
        private CreateResource $createResource,
    ) {}

    /**
     * @param  MenuFolderInput  $root  ルートのメニュー(code がグループ ID)
     *
     * @throws ValidationException 規則に反するとき、無いロケールのコードがあるとき
     */
    public function execute(int $categoryId, MenuFolderInput $root): MenuGroup
    {
        return $this->writeCommonMaster(function () use ($categoryId, $root) {
            $category = MenuGroupCategory::query()->find($categoryId)
                ?? throw ValidationException::withMessages(['menu_group_category_id' => ['作成先のカテゴリがありません']]);
            $rootMenu = $this->createMenu(null, $root)->refresh();
            $group = MenuGroup::query()->create(['menu_id' => $rootMenu->id]);
            $group->menuGroupCategoryInclusions()->create(['menu_group_category_id' => $category->id]);

            $this->createResource->execute(
                MenuResourceKind::Group->resourceGroupCode($rootMenu->code),
                $this->resourceGroupId(MenuResourceKind::Category->resourceGroupCode($category->code)),
                MenuGroupAction::RESOURCE_TYPE,
                MenuResourceKind::Group->uri($rootMenu->code),
                $this->resourceTranslations($root->displayNames),
            );

            return $group;
        });
    }
}
