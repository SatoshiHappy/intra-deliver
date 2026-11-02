<?php

namespace App\Enums\Authz;

use App\Support\RoutePlacement;

/**
 * 認可のリソースタイプ「画面・処理」(service)のアクション(docs の 設計/メニュー/認可との結び付け.md 2.1。ID は本家のまま)。
 * 画面・処理はすべてのモジュールのルートが使うので Authz が定義する。本家「リソースタイプ “service” には、アクションとして “execute”(実行)が 1 つのみ用意されています。」
 */
enum ServiceAction: string
{
    /** リソースタイプの ID(本家のまま) */
    public const RESOURCE_TYPE = 'service';

    /** このタイプのリソース(ルート)を置く、設定できる側のリソースグループセットの ID(本家の予約のセット ID。表示名は本家のセットの名前「画面・処理」) */
    public const SET_CODE = 'http-services';

    /**
     * 設定不可のセットの ID(本家の予約のセット ID。表示名「HTTPサービス(設定不可)」)。可否を別の判定が決める入口と、ユーザーが受け入れた入口の
     * ルートを置き、認可設定の画面に出さない(docs の 設計/メニュー/認可との結び付け.md 2.3 の置き場)
     */
    public const UNMANAGED_SET_CODE = RoutePlacement::UNMANAGED_SET_CODE;

    /** ルートのリソースを置く 2 つのセット(置き場) */
    public const SET_CODES = [self::SET_CODE, self::UNMANAGED_SET_CODE];

    case Execute = 'execute';
}
