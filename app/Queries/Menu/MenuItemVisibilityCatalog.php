<?php

namespace App\Queries\Menu;

use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\Api\MenuItemRoute;
use App\Queries\Menu\Api\MenuItemVisibility;
use DateTimeInterface;
use Illuminate\Container\Attributes\Tag;

/**
 * 追加の判定の実装を束ねて、隠すアイテムの和を取る 1 か所(内部。docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 1.2)。
 */
class MenuItemVisibilityCatalog
{
    /**
     * @param  iterable<MenuItemVisibility>  $visibilities
     */
    public function __construct(#[Tag(MenuItemVisibility::TAG)] private iterable $visibilities) {}

    /**
     * 渡したアイテムのうち、どれかの実装が隠すもの(渡していない id は返さないので、実装が外部サイトのアイテムを隠せない)。
     *
     * @param  array<int, MenuItemRoute>  $items  アイテムの id → 照合で合ったルート
     * @return array<int, true> 隠すアイテムの id → true
     */
    public function hiddenItems(Requester $requester, array $items, DateTimeInterface $date): array
    {
        $hidden = [];

        foreach ($this->visibilities as $visibility) {
            $hidden += array_fill_keys($visibility->hiddenItems($requester, $items, $date), true);
        }

        return array_intersect_key($hidden, $items);
    }
}
