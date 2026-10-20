<?php

namespace App\Enums\Authz;

/**
 * 認可の判定の結果(docs の 設計/共通マスタ/ロールと認可/ポリシーと判定.md の authz_policies の「判定の意味」)。許されるのは許可のときだけ。
 */
enum AuthorizationResult
{
    case Permit;
    case Deny;
    /** 閉塞(本家の Block)。ポリシーによらない */
    case Block;
}
