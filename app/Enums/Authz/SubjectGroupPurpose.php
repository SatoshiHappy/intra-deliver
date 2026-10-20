<?php

namespace App\Enums\Authz;

/**
 * 認可の対象者の用途(authz_subject_groups.purpose。docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_groups)。
 * Policy = 認可のポリシーが指す対象者、Selection = ほかのモジュールの指定(ワークフローの処理対象者・参照者)が指す専用の対象者
 * (作るのは App\Actions\Authz\Api\CreateSelectionSubjectGroup だけ。ポリシーは指せず、ほかの対象者の子にならない)。
 */
enum SubjectGroupPurpose: string
{
    case Policy = 'policy';
    case Selection = 'selection';
}
