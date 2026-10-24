<?php

namespace Tests\Support;

use App\Models\Workflow\WorkflowContent;
use Illuminate\Database\Eloquent\Model;

/**
 * コンテンツに足した申請対象の列と外部キー(docs の 設計/ワークフロー/データモデル/表.md の 2.1 節)。表のほかの列と外部キーは
 * {@see WorkflowSchema}、制約と索引の名前は {@see WorkflowConstraintNames}。列の書き方は {@see WorkflowSchema} と同じ。
 */
final class WorkflowContentTargetSchema
{
    /**
     * 表を作った後に足した列(表 → 列。表の末尾に付く = SchemaTest の COLUMNS_ADDED_LATER)。
     *
     * @var array<string, array<string, string>>
     */
    public const COLUMNS_ADDED_LATER = [
        'workflow_contents' => [
            'target_record_field_id' => 'bigint',
            'target_record_module_id' => 'bigint',
        ],
    ];

    /**
     * 外部キー(書き方は {@see WorkflowSchema::FOREIGN_KEYS} と同じ)。参照先の側のリレーションは、業務データの入れ物がワークフローを
     * 知らないので null。2 本とも同じ項目の行を指すので、どちらも参照する側のリレーションは申請対象の項目。
     *
     * @var list<array{class-string<Model>, string|null, list<string>, string, list<string>, string, string,
     *     string|null}>
     */
    public const FOREIGN_KEYS = [
        [WorkflowContent::class, 'withTarget', ['record_module_id', 'target_record_field_id'], 'record_fields',
            ['record_module_id', 'id'], 'restrict', 'targetRecordField', null],
        [WorkflowContent::class, 'withTarget', ['target_record_field_id', 'target_record_module_id'], 'record_fields',
            ['id', 'referenced_record_module_id'], 'restrict', 'targetRecordField', null],
    ];
}
