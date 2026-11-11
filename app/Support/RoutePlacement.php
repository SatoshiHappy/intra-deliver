<?php

namespace App\Support;

/**
 * ルートの置き場の宣言(docs の 設計/メニュー/認可との結び付け/ルートのリソース.md 2.3 の置き場、ADR 0033 の決定 1): 設定不可のセットに置くルートに、
 * ルートの定義(routes/)が Route::metadata(RoutePlacement::UNMANAGED)->group(...) で付け、Authz の RouteResources が読む。
 * 宣言はルートの action に入るので、ルートのキャッシュの後も残る。配線が依存してよい共有の層に置き、値の正本をここ 1 か所にする。
 */
final class RoutePlacement
{
    /** 設定不可のセットの ID(本家の予約のセット ID) */
    public const UNMANAGED_SET_CODE = 'http-services-unmanaged';

    /** 置き場の宣言を読む鍵(ルートの metadata の中の道) */
    public const METADATA_KEY = 'authz.set';

    /** 設定不可のセットに置くルートに付ける宣言(ルートの metadata) */
    public const UNMANAGED = ['authz' => ['set' => self::UNMANAGED_SET_CODE]];
}
