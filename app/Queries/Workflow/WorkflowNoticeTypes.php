<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowNoticeType;
use App\Queries\Notice\Api\NoticeTypes;

/**
 * ワークフローの通知の種類の一覧(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 1 節)。
 * 列挙の値のすべてを宣言の順に答える。起動がタグ {@see NoticeTypes::TAG} で登録する。
 */
class WorkflowNoticeTypes implements NoticeTypes
{
    public function types(): array
    {
        return array_map(fn (WorkflowNoticeType $type): string => $type->value, WorkflowNoticeType::cases());
    }
}
