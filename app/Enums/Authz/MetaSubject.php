<?php

namespace App\Enums\Authz;

/**
 * 認可の対象者の条件の認証状態(authz_subject_conditions.meta_subject。本家のメタサブジェクト ID)。
 * 判定では d に有効なユーザーの有無で決まる(いる = Authenticated、いない = Anonymous)。
 */
enum MetaSubject: string
{
    case Anonymous = 'anonymous';
    case Authenticated = 'authenticated';
}
