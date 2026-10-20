<?php

namespace App\Queries\Menu\Api;

use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\MenuItemVisibilityCatalog;
use DateTimeInterface;

/**
 * メニューのアイテムの表示判定に足す追加の判定の口(依存の逆転 = docs の 設計/コード構成/README.md 3 節。保証の正本 =
 * 設計/ワークフロー/申請の周辺/メニューと導線.md 1.1・1.2)。ルートを受け持つ後ろのモジュールが自分の読み取りの層に実装し、
 * 起動(App\Providers)がタグ {@see self::TAG} で登録する。表示判定は実装を直接呼ばず、束ねる 1 か所(内部の {@see MenuItemVisibilityCatalog})を通す。
 *
 * 表示判定の 1 回につき 1 回呼ばれる(アイテムごとには呼ばれない)。結果を要求をまたいで覚えない。DB の例外は捕まえずに上へ出す。
 */
interface MenuItemVisibility
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'menu.item-visibility';

    /**
     * ルートの execute で表示と決まったアプリの中のパスのアイテムのうち、この実装が隠すもの。受け持たないルートのアイテムは結果に入れない。
     *
     * @param  array<int, MenuItemRoute>  $items  アイテムの id → 照合で合ったルート
     * @return list<int> 隠すアイテムの id
     */
    public function hiddenItems(Requester $requester, array $items, DateTimeInterface $date): array;
}
