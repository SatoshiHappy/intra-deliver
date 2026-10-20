<?php

namespace App\Enums\Authz;

/**
 * 用途 selection の対象者の条件の種類(App\Actions\Authz\Api\SelectionCondition の種類。docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1)。
 * 値は条件の行の列(authz_subject_conditions の user_id・department_id・company_post_id・role_id)の名前の前の部分。
 */
enum SelectionConditionKind: string
{
    case User = 'user';
    case Department = 'department';
    case CompanyPost = 'company_post';
    case Role = 'role';
}
