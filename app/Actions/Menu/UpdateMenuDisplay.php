<?php

namespace App\Actions\Menu;

use App\Actions\Master\ResolvesLocales;
use App\Actions\Master\WritesCommonMaster;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use Illuminate\Validation\ValidationException;

/**
 * メニューの表示名とアイコンを置き換える(docs の 設計/メニュー/表.md 1.7 の 5)。渡されたメニュー ID のうち、あるものだけを渡された順に変え
 * (無いものは飛ばす)、変えたメニュー ID を返す。種別が folder・item でないもの(外部メニューリンク)が 1 つでもあれば拒み、何も書かない。
 * 表示名はロケールのコード → 表示名で、入力にあるロケールは行があれば更新し、無ければ作る。入力に無いロケールの行は変えない。
 * icon_32 は入力の値に置き換える(NULL = アイコン無し。値は検査しない = 設計/画面の意匠/サイドバーとアイコン.md 5.1)。ほかの列は変えない。
 * 呼び手(業務モジュールの名前とアイコンの写しの追従 = 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 5.1)に要る分だけの狭い規則で、
 * メニュー管理画面を作るときに広げる。
 */
class UpdateMenuDisplay
{
    use ResolvesLocales;
    use WritesCommonMaster;

    /**
     * @param  list<string>  $codes  メニュー ID(この順に変える)
     * @param  array<string, string>  $displayNames  ロケールのコード → 表示名
     * @return list<string> 変えたメニュー ID(渡された順。無いものは含まない)
     *
     * @throws ValidationException 種別が folder・item でないメニューがあるとき、無いロケールのコードがあるとき
     */
    public function execute(array $codes, array $displayNames, ?string $icon32): array
    {
        $codes = array_values(array_unique($codes));

        return $this->writeCommonMaster(function () use ($codes, $displayNames, $icon32) {
            $found = Menu::query()->whereIn('code', $codes)->get()->keyBy('code');
            $menus = array_values(array_filter(array_map(fn (string $code) => $found->get($code), $codes)));
            $invalid = array_find($menus, fn (Menu $menu) => ! in_array($menu->type, [MenuType::Folder, MenuType::Item], true));

            if ($invalid !== null) {
                throw ValidationException::withMessages(['menu' => ["表示名とアイコンを変更できるのはメニューフォルダかメニューアイテムだけです: {$invalid->code}"]]);
            }

            if ($menus === []) {
                return [];
            }

            $localeIds = $this->localeIds($displayNames);

            foreach ($menus as $menu) {
                $this->replaceDisplay($menu, $localeIds, $displayNames, $icon32);
            }

            return array_map(fn (Menu $menu) => $menu->code, $menus);
        });
    }

    /**
     * メニュー 1 件の表示名(入力にあるロケールは更新か作成)と icon_32 を書く。
     *
     * @param  array<string, int>  $localeIds  ロケールのコード → id
     * @param  array<string, string>  $displayNames  ロケールのコード → 表示名
     */
    private function replaceDisplay(Menu $menu, array $localeIds, array $displayNames, ?string $icon32): void
    {
        foreach ($displayNames as $localeCode => $displayName) {
            $menu->menuTranslations()->updateOrCreate(['locale_id' => $localeIds[$localeCode]], ['display_name' => $displayName]);
        }

        $menu->update(['icon_32' => $icon32]);
    }
}
