<?php

namespace App\Queries\Records;

use App\Enums\Menu\WorkMenuGroup;

/**
 * 業務モジュールの表示の状態の読み取り(docs の 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 5.1、モジュール分類/README.md 1.4)。
 * 表示中の判定を 1 か所に置き、業務処理・一覧・モジュール分類の画面の結果が分かれないようにする。
 * 明細のモジュールは表示の状態を持たないので、呼び手が親の無い業務モジュールのコードだけを渡す。
 */
class RecordModuleVisibility
{
    public function __construct(private ModuleCategoryQuery $categories) {}

    /**
     * 渡したコードのうち表示中の業務モジュールのコード(渡した順。問い合わせは渡したコードで絞り、数はコードの数に依らない)。
     *
     * @param  list<string>  $moduleCodes
     * @return list<string>
     */
    public function visibleCodes(array $moduleCodes): array
    {
        $group = WorkMenuGroup::GlobalNavigation;
        $targets = [];

        foreach ($moduleCodes as $moduleCode) {
            $targets[$group->recordModuleItemCode($moduleCode)] = $group->value;
        }

        $direct = array_flip($this->categories->placedItemCodes($targets));
        $categorized = $this->categories->memberships($moduleCodes);

        return array_values(array_filter($moduleCodes, fn (string $code) => isset($direct[$group->recordModuleItemCode($code)]) || isset($categorized[$code])));
    }

    /**
     * その業務モジュールが表示中か。
     */
    public function isVisible(string $moduleCode): bool
    {
        return $this->visibleCodes([$moduleCode]) !== [];
    }
}
