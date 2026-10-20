<?php

namespace App\Actions\Menu;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use Illuminate\Validation\ValidationException;

/**
 * メニューのソート番号を変える(docs の 設計/メニュー/表.md 1.7 の 6、業務データの入れ物/モジュール分類/業務処理.md 3.8)。メニュー ID → ソート番号を受け、
 * あるものだけを変え(無いものは飛ばす)、変えたメニュー ID を返す。種別が folder・item でないもの(外部メニューリンク)、範囲(共通マスタ/共通の規則.md 4 節の
 * ソートキー = ±999,999,999,999,999)の外の値・整数でない値が 1 つでもあれば拒み、何も書かない。ほかの列は変えない。
 * 呼び手(モジュール分類の並び = モジュール分類/README.md 2 節)に要る分だけの狭い規則で、メニュー管理画面を作るときに広げる。
 */
class UpdateMenuSortNumbers
{
    use WritesCommonMaster;

    /** ソート番号の絶対値の上限(menus_sort_number_check と同じ) */
    private const SORT_NUMBER_MAX = 999_999_999_999_999;

    /**
     * @param  array<string, mixed>  $sortNumbers  メニュー ID → ソート番号(この順に変える)
     * @return list<string> 変えたメニュー ID(渡された順。無いものは含まない)
     *
     * @throws ValidationException 範囲の外・整数でないソート番号、種別が folder・item でないメニューがあるとき(キー menu)
     */
    public function execute(array $sortNumbers): array
    {
        foreach ($sortNumbers as $code => $sortNumber) {
            if (! is_int($sortNumber) || abs($sortNumber) > self::SORT_NUMBER_MAX) {
                throw ValidationException::withMessages(['menu' => ["ソート番号は 15 桁までの整数にしてください: {$code}"]]);
            }
        }

        return $this->writeCommonMaster(function () use ($sortNumbers) {
            $found = Menu::query()->whereIn('code', array_map(strval(...), array_keys($sortNumbers)))->get()->keyBy('code');
            $menus = array_values(array_filter(array_map(fn (int|string $code) => $found->get((string) $code), array_keys($sortNumbers))));
            $invalid = array_find($menus, fn (Menu $menu) => ! in_array($menu->type, [MenuType::Folder, MenuType::Item], true));

            if ($invalid !== null) {
                throw ValidationException::withMessages(['menu' => ["ソート番号を変更できるのはメニューフォルダかメニューアイテムだけです: {$invalid->code}"]]);
            }

            foreach ($menus as $menu) {
                $menu->update(['sort_number' => $sortNumbers[$menu->code]]);
            }

            return array_map(fn (Menu $menu) => $menu->code, $menus);
        });
    }
}
