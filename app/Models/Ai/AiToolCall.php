<?php

namespace App\Models\Ai;

use App\Enums\Ai\AiToolCallAnswer;
use App\Enums\Ai\AiToolCallDecision;
use App\Enums\Ai\AiToolCallStatus;
use Database\Factories\Ai\AiToolCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 会話の型の道具の呼び出し 1 回。表 ai_tool_calls(docs の 設計/AI/実行の記録.md 2.4)。
 * 期限で消す値は行ごと消せるように別の表に持つ(設計/AI/データと失敗と検査.md 6 節)。記録の表なので更新日時の列を持たない。
 */
#[Fillable([
    'ai_run_id', 'ai_run_step_id', 'position', 'call_id', 'tool_name', 'decision', 'decision_reason', 'answer',
    'answer_run_id', 'status', 'records', 'confirmation_version', 'finished_at',
])]
class AiToolCall extends Model
{
    /** @use HasFactory<AiToolCallFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'decision' => AiToolCallDecision::class,
            'answer' => AiToolCallAnswer::class,
            'status' => AiToolCallStatus::class,
            'records' => 'array',
            'confirmation_version' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    /**
     * 応答にこの呼び出しが入ったステップ。
     *
     * @return BelongsTo<AiRunStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(AiRunStep::class, 'ai_run_step_id');
    }

    /**
     * 確認の答えを書いた実行(答えが無ければ null)。
     *
     * @return BelongsTo<AiRun, $this>
     */
    public function answerRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'answer_run_id');
    }

    /**
     * 引数・結果・確認の内容・レコードの表示の名前(保存期間を過ぎて消した後は null)。
     *
     * @return HasOne<AiToolCallText, $this>
     */
    public function text(): HasOne
    {
        return $this->hasOne(AiToolCallText::class);
    }
}
