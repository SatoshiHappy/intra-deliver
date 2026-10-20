<?php

namespace App\Models\Workflow;

use App\Models\Records\RecordModule;
use Database\Factories\Workflow\WorkflowContentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * コンテンツ(申請フォームに使う業務モジュールを決める、期間なしのマスタ)。表 workflow_contents(docs の 設計/ワークフロー/データモデル/表.md 2.1)。
 * 生成列 is_detail はアプリが書かず読まない(複合外部キーのための列)。
 */
#[Fillable(['code', 'name', 'record_module_id'])]
class WorkflowContent extends Model
{
    /** @use HasFactory<WorkflowContentFactory> */
    use HasFactory;

    /**
     * 申請フォームに使う業務モジュール(親の無いもの)。
     *
     * @return BelongsTo<RecordModule, $this>
     */
    public function recordModule(): BelongsTo
    {
        return $this->belongsTo(RecordModule::class);
    }

    /**
     * @return HasMany<WorkflowFlow, $this>
     */
    public function workflowFlows(): HasMany
    {
        return $this->hasMany(WorkflowFlow::class);
    }
}
