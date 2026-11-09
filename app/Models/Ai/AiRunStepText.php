<?php

namespace App\Models\Ai;

use Database\Factories\Ai\AiRunStepTextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ステップに送った文と応答の原文(ステップ 1 回に 1 行。主キー = ステップ)。表 ai_run_step_texts(docs の 設計/AI/実行の記録.md 2.2・2.3)。
 * 作ってから保存期間を過ぎた行を消す(設計/AI/データと失敗と検査.md 6 節)。送った文は要求の本体を文字のまま持つ(jsonb は鍵の順を変えるため)。
 */
#[Fillable(['ai_run_step_id', 'sent_text', 'output_text'])]
class AiRunStepText extends Model
{
    /** @use HasFactory<AiRunStepTextFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $primaryKey = 'ai_run_step_id';

    public $incrementing = false;

    /**
     * @return BelongsTo<AiRunStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(AiRunStep::class, 'ai_run_step_id');
    }
}
