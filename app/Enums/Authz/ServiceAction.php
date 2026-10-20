<?php

namespace App\Enums\Authz;

/**
 * 認可のリソースタイプ「画面・処理」(service)のアクション(docs の 設計/メニュー/認可との結び付け.md 2.1。ID は本家のまま)。
 * 画面・処理はすべてのモジュールのルートが使うので Authz が定義する。本家「リソースタイプ “service” には、アクションとして “execute”(実行)が 1 つのみ用意されています。」
 */
enum ServiceAction: string
{
    /** リソースタイプの ID(本家のまま) */
    public const RESOURCE_TYPE = 'service';

    /** このタイプのリソース(ルート)を置くリソースグループセットの ID(本家の予約のセット ID。表示名は本家のセットの名前「画面・処理」) */
    public const SET_CODE = 'http-services';

    case Execute = 'execute';
}
