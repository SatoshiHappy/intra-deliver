<?php

namespace Tests\Support;

use App\Models\Workflow\WorkflowCaseDraftAttachment;
use Illuminate\Database\Eloquent\Model;

/**
 * 一時保存の添付の表の設計どおりの列と外部キー(docs の 設計/ワークフロー/申請の周辺/データモデル.md の 3.2 節・3.3 節)。
 * 既存の表に足した添付の列は {@see WorkflowSchema} の COLUMNS_ADDED_LATER、制約と索引の名前は {@see WorkflowConstraintNames}。
 * 列の書き方は {@see WorkflowSchema} と同じ。
 */
final class WorkflowAttachmentSchema
{
    /** 表 → モデル(テストのデータを factory で作るため) */
    public const MODELS = [
        'workflow_case_draft_attachments' => WorkflowCaseDraftAttachment::class,
    ];

    /** @var array<string, array<string, string>> */
    public const COLUMNS = [
        'workflow_case_draft_attachments' => [
            'workflow_case_id' => 'bigint not null',
            'upload_id' => 'bigint not null',
            'name' => 'character varying(255) not null',
        ],
    ];

    /**
     * 外部キー(書き方は {@see WorkflowSchema::FOREIGN_KEYS} と同じ)。参照先の側のリレーションは、案件の側は使い手がまだ無く、
     * 台帳の側は持ち主を知らないので null。
     *
     * @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string,
     *     string|null}>
     */
    public const FOREIGN_KEYS = [
        [WorkflowCaseDraftAttachment::class, null, ['workflow_case_id'], 'workflow_cases',
            ['id'], 'cascade', 'workflowCase', null],
        [WorkflowCaseDraftAttachment::class, null, ['upload_id'], 'uploads',
            ['id'], 'restrict', 'upload', null],
    ];
}
