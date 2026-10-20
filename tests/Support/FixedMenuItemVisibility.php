<?php

namespace Tests\Support;

use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\Api\MenuItemRoute;
use App\Queries\Menu\Api\MenuItemVisibility;
use DateTimeInterface;

/**
 * テストの中だけで使う追加の判定の実装(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 1.2): 決めたアイテムを隠すと答え、
 * 受け取ったアイテムを呼ばれた順に残す(呼ばれた回数を確かめるため)。
 */
final class FixedMenuItemVisibility implements MenuItemVisibility
{
    /** @var list<array<int, MenuItemRoute>> */
    private array $received = [];

    /**
     * @param  list<int>  $hiddenIds  隠すと答えるアイテムの id(受け取ったかによらず答える)
     */
    public function __construct(private readonly array $hiddenIds = []) {}

    public function hiddenItems(Requester $requester, array $items, DateTimeInterface $date): array
    {
        $this->received[] = $items;

        return $this->hiddenIds;
    }

    /**
     * 呼ばれるたびに受け取ったアイテム(アイテムの id → 照合で合ったルート)。
     *
     * @return list<array<int, MenuItemRoute>>
     */
    public function received(): array
    {
        return $this->received;
    }
}
