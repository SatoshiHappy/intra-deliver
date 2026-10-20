<?php

namespace Tests\Support;

use App\Actions\Records\UpdateRecordModule;
use App\Enums\Menu\WorkMenuGroup;
use App\Enums\Records\RecordModuleVisibilityChange;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroupTranslation;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuTranslation;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\Builder;

/**
 * 業務モジュールを変える業務処理({@see UpdateRecordModule})のテストの手助け: 呼び出しと、業務モジュール・認可のリソースグループの表示名・
 * 「業務」のアイテム(表示名・アイコン・行)・認可とメニューの表の写しの読み取り。
 */
trait ReadsRecordModuleMenus
{
    /**
     * @param  array<string, mixed>  $input
     */
    private function update(string $moduleCode, array $input): RecordModuleVisibilityChange
    {
        return app(UpdateRecordModule::class)->execute($moduleCode, $input);
    }

    /**
     * 業務モジュールのアイテムの行(メニュー ID → id・親・並び。メニュー ID の順)。
     *
     * @return array<string, array<string, mixed>>
     */
    private function itemRows(string $moduleCode): array
    {
        return $this->itemQuery($moduleCode)->get(['id', 'code', 'parent_id', 'sort_number'])
            ->mapWithKeys(fn (Menu $item) => [$item->code => $item->only(['id', 'parent_id', 'sort_number'])])
            ->all();
    }

    /**
     * [名前, 説明, アイコン]。
     *
     * @return array{string, string|null, string|null}
     */
    private function moduleValues(string $code): array
    {
        $module = RecordModule::query()->where('code', $code)->sole();

        return [$module->name, $module->description, $module->icon];
    }

    /**
     * 認可のリソースグループ record-module-<コード> の表示名(ロケールのコード → 表示名。コードの順)。
     *
     * @return array<string, string>
     */
    private function resourceGroupNames(string $moduleCode): array
    {
        return AuthzResourceGroupTranslation::query()->with('locale')->whereRelation('authzResourceGroup', 'code', "record-module-{$moduleCode}")->get()
            ->mapWithKeys(fn (AuthzResourceGroupTranslation $translation) => [(string) $translation->locale?->code => $translation->display_name])
            ->sortKeys()->all();
    }

    /**
     * 業務モジュールのアイテム(メニュー ID → [表示名(ロケールのコード → 表示名。コードの順), icon_32])。
     *
     * @return array<string, array{array<string, string>, string|null}>
     */
    private function items(string $moduleCode): array
    {
        return $this->itemQuery($moduleCode)->with('menuTranslations.locale')->get()
            ->mapWithKeys(fn (Menu $item) => [$item->code => [
                $item->menuTranslations->mapWithKeys(fn (MenuTranslation $translation) => [(string) $translation->locale?->code => $translation->display_name])->sortKeys()->all(),
                $item->icon_32,
            ]])
            ->all();
    }

    /**
     * 業務モジュールの 2 つのアイテム(「業務」のグループ)の問い合わせ(メニュー ID の順)。
     *
     * @return Builder<Menu>
     */
    private function itemQuery(string $moduleCode): Builder
    {
        return Menu::query()->whereIn('code', WorkMenuGroup::recordModuleItemCodes($moduleCode))->orderBy('code');
    }

    /**
     * 認可のリソースグループの表示名・リソース・ポリシーの数と、メニューの行・表示名(ID → 表示名・アイコン)。
     *
     * @return array<string, mixed>
     */
    private function authorizationAndMenus(): array
    {
        return [
            'resourceGroupNames' => AuthzResourceGroupTranslation::query()->orderBy('id')->get(['authz_resource_group_id', 'locale_id', 'display_name', 'description'])->toArray(),
            'resources' => AuthzResource::query()->count(),
            'policies' => AuthzPolicy::query()->count(),
            'menus' => Menu::query()->orderBy('id')->get(['code', 'type', 'sort_number', 'icon_32'])->toArray(),
            'menuNames' => MenuTranslation::query()->orderBy('id')->get(['menu_id', 'locale_id', 'display_name'])->toArray(),
        ];
    }
}
