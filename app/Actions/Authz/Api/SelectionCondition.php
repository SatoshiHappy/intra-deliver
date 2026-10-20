<?php

namespace App\Actions\Authz\Api;

use App\Enums\Authz\SelectionConditionKind;

/**
 * 用途 selection の対象者の条件 1 つ({@see CreateSelectionSubjectGroup} に渡す値。docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1)。
 * 種類と相手の id だけを持つ(比較は eq だけ。除外・入れ子・期間などの種類は持たない)。
 * 例: 組織 = new SelectionCondition(SelectionConditionKind::Department, 12)。
 */
final readonly class SelectionCondition
{
    /**
     * @param  int  $id  相手の id(種類に応じて users・departments・company_posts・roles の id)
     */
    public function __construct(
        public SelectionConditionKind $kind,
        public int $id,
    ) {}
}
