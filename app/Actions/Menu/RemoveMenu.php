<?php

namespace App\Actions\Menu;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Menu\MenuType;
use App\Models\Menu\Menu;
use Illuminate\Validation\ValidationException;

/**
 * メニューを消す(docs の 設計/メニュー/表.md 1.7 の 4)。渡されたメニュー ID のうち、あるものだけを渡された順に消し(無いものは飛ばす。
 * 同じ ID は 1 つにする)、消したメニュー ID を返す。消せるのは種別 item と、親を持つ種別 folder(グループのルートでない)で、子を持たず、
 * コピーの元(ほかのメニューの original_menu_id が指す)でないものだけで、1 つでも当たれば拒み、何も消さない(フォルダの中身は呼び手が先に消す。
 * 連鎖で消さない)。表示名・引数は連鎖(CASCADE)で消える。業務データの入れ物のメニューのアイテムを揃える業務処理(設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 5.1・
 * モジュール分類/業務処理.md 3.7)とモジュール分類の削除(モジュール分類/業務処理.md 3.4・3.8)に要る分だけの狭い規則で、メニュー管理画面を作るときに広げる。
 */
class RemoveMenu
{
    use WritesCommonMaster;

    /**
     * @param  list<string>  $codes  メニュー ID(この順に消す)
     * @return list<string> 消したメニュー ID(渡された順。無いものは含まない)
     *
     * @throws ValidationException 消せないメニュー(種別が item でもグループのルートでない folder でもない・子を持つ・コピーの元)があるとき
     */
    public function execute(array $codes): array
    {
        $codes = array_values(array_unique($codes));

        return $this->writeCommonMaster(function () use ($codes) {
            $found = Menu::query()->whereIn('code', $codes)->withExists(['children', 'copies'])->get()->keyBy('code');
            $menus = array_values(array_filter(array_map(fn (string $code) => $found->get($code), $codes)));

            foreach ($menus as $menu) {
                $reason = $this->rejection($menu);

                if ($reason !== null) {
                    throw ValidationException::withMessages(['menu' => ["{$reason}: {$menu->code}"]]);
                }
            }

            foreach ($menus as $menu) {
                $menu->delete();
            }

            return array_map(fn (Menu $menu) => $menu->code, $menus);
        });
    }

    /**
     * 消せない理由(消せるなら null)。children_exists・copies_exists は withExists が読んだもの。
     */
    private function rejection(Menu $menu): ?string
    {
        return match (true) {
            ! $this->isRemovableType($menu) => '削除できるのはメニューアイテムと、グループのルートでないメニューフォルダだけです',
            (bool) $menu->getAttribute('children_exists') => '子のあるメニューは削除できません',
            (bool) $menu->getAttribute('copies_exists') => 'コピーの元のメニューは削除できません',
            default => null,
        };
    }

    /**
     * 種別 item か、親を持つ種別 folder(グループのルートは親を持たない)。
     */
    private function isRemovableType(Menu $menu): bool
    {
        return $menu->type === MenuType::Item || ($menu->type === MenuType::Folder && $menu->parent_id !== null);
    }
}
