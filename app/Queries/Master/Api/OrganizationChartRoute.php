<?php

namespace App\Queries\Master\Api;

/**
 * 組織図のルートの名前(docs の 設計/共通マスタ/検索と組織図.md 1 節・3.2)。ルートの定義(組織図のコントローラの定数を通す)と、
 * 組織図へ移れるかの判定(見出しの検索欄の出し分け = Menu の骨格の共有)が使う、名前の正本。
 *
 * Master の公開の口(値だけ。判定しない。鍵を取らない)。
 */
final class OrganizationChartRoute
{
    public const NAME = 'master.organization-chart';
}
