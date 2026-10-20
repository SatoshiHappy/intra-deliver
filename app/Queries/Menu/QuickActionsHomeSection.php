<?php

namespace App\Queries\Menu;

use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\Api\HomeSections;
use App\Support\AccessContext;

/**
 * ホームの主な操作の節(docs の 設計/メニュー/ホーム.md 6.5。口 = {@see HomeSections})。カテゴリ home_quick_actions_pc の表示判定
 * (サイドバーと同じ = 表示.md 3.1)を通った表示するアイテムを、グループ・フォルダの並びの順に平らにして 1 つ 1 枚のタイルにする
 * (code = メニュー ID、label = 表示名(無ければメニュー ID)、icon・url(= href)・isExternal はアイテムの値)。表示するアイテムが無ければ null。
 * 出し分けはポリシーだけで決まる(グループの read は認証済みユーザだけに許すのでゲストには出ない。ルートの execute を許されないタイルは出ない)。
 * 鍵を取らない。例外を投げない(読むのはメニューの表と認可の判定だけ)。
 *
 * @phpstan-import-type HomeTilesSection from HomeSections
 */
class QuickActionsHomeSection implements HomeSections
{
    public function __construct(private MenuDisplayQuery $menus) {}

    /**
     * @return HomeTilesSection|null
     */
    public function section(AccessContext $context): ?array
    {
        $items = $this->menus->visibleItems(MenuDisplayQuery::HOME_QUICK_ACTIONS_CATEGORY, Requester::fromAccessContext($context), $context->date, $context->localeCode);

        if ($items === []) {
            return null;
        }

        return [
            'code' => 'menu-quick-actions',
            'kind' => 'tiles',
            'area' => 'side',
            'heading' => '主な操作',
            'items' => array_map(fn (array $item) => [
                'code' => $item['code'],
                'label' => $item['name'] ?? $item['code'],
                'icon' => $item['icon'],
                'url' => $item['href'],
                'isExternal' => $item['isExternal'],
            ], $items),
        ];
    }
}
