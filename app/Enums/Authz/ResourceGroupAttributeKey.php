<?php

namespace App\Enums\Authz;

/**
 * Authz モジュールが使うリソースグループの汎用属性のキー(authz_resource_group_attributes.key。キーはほかのモジュールも足すので、列のキャストにはしない)。
 */
enum ResourceGroupAttributeKey: string
{
    /** 閉塞。値は ALL(リソースグループ自体)か、<リソースタイプ>:<アクション> をカンマで区切って並べたもの(本家のキーのまま) */
    case Blocked = 'im_authz-blocked';
}
