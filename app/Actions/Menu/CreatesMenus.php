<?php

namespace App\Actions\Menu;

use App\Actions\Master\ResolvesLocales;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Menu\Menu;
use Illuminate\Validation\ValidationException;

/**
 * メニューの業務処理が共通に使う書き込み(トランザクションの中で呼ぶ): メニューの行と表示名・引数を作る、認可のリソースグループを ID で引く、
 * 表示名を認可のリソースグループの表示名の形に写す。
 */
trait CreatesMenus
{
    use ResolvesLocales;

    /**
     * メニューの行と表示名(と、アイテムなら引数)を作る。
     *
     * @throws ValidationException 無いロケールのコードがあるとき
     */
    private function createMenu(?int $parentId, MenuFolderInput|MenuItemInput $input): Menu
    {
        $localeIds = $this->localeIds($input->displayNames);
        $menu = Menu::query()->create([...$input->attributes(), 'parent_id' => $parentId]);

        foreach ($input->displayNames as $localeCode => $displayName) {
            $menu->menuTranslations()->create(['locale_id' => $localeIds[$localeCode], 'display_name' => $displayName]);
        }

        foreach ($input instanceof MenuItemInput ? $input->arguments : [] as $name => $value) {
            $menu->menuArguments()->create(['name' => $name, 'value' => $value]);
        }

        return $menu;
    }

    /**
     * 認可のリソースグループの id(同期で作るリソースの親)。
     *
     * @throws ValidationException 無いとき
     */
    private function resourceGroupId(string $code): int
    {
        return AuthzResourceGroup::query()->where('code', $code)->value('id')
            ?? throw ValidationException::withMessages(['resource_group' => ["認可のリソースグループがありません: {$code}"]]);
    }

    /**
     * 表示名(ロケールのコード → 表示名)を、認可のリソースグループの表示名の形(説明は NULL)に写す。
     *
     * @param  array<string, string>  $displayNames
     * @return array<string, array{display_name: string, description: null}>
     */
    private function resourceTranslations(array $displayNames): array
    {
        return array_map(fn (string $displayName) => ['display_name' => $displayName, 'description' => null], $displayNames);
    }
}
