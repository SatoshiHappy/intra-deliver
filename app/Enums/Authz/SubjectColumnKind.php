<?php

namespace App\Enums\Authz;

/**
 * 認可設定の「列を追加」で選ぶ相手の種類(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3.1 の入力 kind)。列の条件は 1 つで、相手はこの 4 種だけ。
 */
enum SubjectColumnKind: string
{
    case User = 'user';
    case Role = 'role';
    case Department = 'department';
    case CompanyPost = 'post';
}
