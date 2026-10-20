<?php

namespace App\Models\Ai;

use Database\Factories\Ai\AiRunTextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AI に送った文と応答の原文(実行 1 回に 1 行。主キー = 実行)。表 ai_run_texts(docs の 設計/AI/README.md 2.2)。
 * 作ってから保存期間を過ぎた行を消す(設計/AI/データと失敗と検査.md 6 節)。応答の原文は構造化出力の JSON の文字列で、応答が無ければ null。
 */
#[Fillable(['ai_run_id', 'system_text', 'user_text', 'output_text'])]
class AiRunText extends Model
{
    /** @use HasFactory<AiRunTextFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $primaryKey = 'ai_run_id';

    public $incrementing = false;

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }
}
