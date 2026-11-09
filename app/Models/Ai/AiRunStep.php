<?php

namespace App\Models\Ai;

use App\Enums\Ai\AiConversationPurpose;
use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStepSendKind;
use App\Enums\Ai\AiRunStepStatus;
use Database\Factories\Ai\AiRunStepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 会話の型の実行のステップ(接続先への送信 1 回)。表 ai_run_steps(docs の 設計/AI/実行の記録.md 2.3、見積もり = 設計/AI/実行と費用/接続先と費用.md 5.3)。
 * 種類の型を写した列を持つのは、会話の型でない実行のステップを外部キーで止めるため。記録の表なので更新日時の列を持たない。
 */
#[Fillable([
    'ai_run_id', 'run_kind_type', 'sequence', 'purpose', 'send_kind', 'retry_attempt', 'retried_from_step_id',
    'ai_tool_call_id', 'provider', 'model', 'reserved_cost_usd', 'input_tokens', 'output_tokens',
    'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens', 'pricing', 'cost_usd',
    'code_execution_seconds', 'status', 'error_code', 'started_at', 'finished_at',
])]
class AiRunStep extends Model
{
    /** @use HasFactory<AiRunStepFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var array<string, string> 種類の型の列は会話の型だけ(検査制約) */
    protected $attributes = ['run_kind_type' => AiKindType::Conversation->value];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run_kind_type' => AiKindType::class,
            'sequence' => 'integer',
            'purpose' => AiConversationPurpose::class,
            'send_kind' => AiRunStepSendKind::class,
            'retry_attempt' => 'integer',
            'reserved_cost_usd' => 'decimal:6',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_input_tokens' => 'integer',
            'cache_write_input_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'pricing' => 'array',
            'cost_usd' => 'decimal:6',
            'code_execution_seconds' => 'decimal:3',
            'status' => AiRunStepStatus::class,
            'error_code' => AiRunErrorCode::class,
            'started_at' => 'datetime',
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
     * 送り直した元のステップ(送り直しでなければ null)。
     *
     * @return BelongsTo<AiRunStep, $this>
     */
    public function retriedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'retried_from_step_id');
    }

    /**
     * 中で呼んだ道具の行(ファイルを作る道具の中の呼び出しだけ)。
     *
     * @return BelongsTo<AiToolCall, $this>
     */
    public function callingToolCall(): BelongsTo
    {
        return $this->belongsTo(AiToolCall::class, 'ai_tool_call_id');
    }

    /**
     * このステップの応答に入った道具の呼び出し。
     *
     * @return HasMany<AiToolCall, $this>
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AiToolCall::class);
    }

    /**
     * 送った文と応答の原文(保存期間を過ぎて消した後は null)。
     *
     * @return HasOne<AiRunStepText, $this>
     */
    public function text(): HasOne
    {
        return $this->hasOne(AiRunStepText::class);
    }
}
