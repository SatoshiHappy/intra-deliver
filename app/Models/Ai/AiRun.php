<?php

namespace App\Models\Ai;

use App\Enums\Ai\AiConversationAnswer;
use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Ai\AiRunStopReason;
use App\Models\Master\User;
use Database\Factories\Ai\AiRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * AI の実行(呼び手の依頼 1 回。文章は LLM の呼び出し 1 回、文字起こしは音声 1 本、会話の型は利用者の 1 操作)。表 ai_runs(docs の 設計/AI/実行の記録.md 2.1)。
 * 記録の表で、業務レコードと音声の実体を消しても残る。種類の型(kind_type)ごとに持つ列の組は検査制約が止める: 文字起こしの行は音声の長さと元の音声を持ち、
 * 出力の上限を持たない。会話の型の行は定義・題を作るか・前の実行などを持ち、共通の 1 つの接続先とモデル・見積もり・対象のレコード・使用量と費用を持たない
 * (用途ごとの値とステップの行が持つ。状態の確認待ち・止めたと失敗の種類の setting・confirmation も会話の型だけ)。
 * 使用量・単価・費用は分かったときに一緒に書き、分からない失敗は null のまま(列の組は検査制約が止める)。更新日時の列を持たない(状態の時刻は started_at・finished_at)。
 */
#[Fillable([
    'kind', 'kind_type', 'status', 'requester_user_id', 'selected_department_id', 'visitor_id', 'record_id',
    'original_record_id', 'record_module_code', 'source_record_ids', 'input_upload_id', 'original_input_upload_id',
    'audio_seconds', 'provider', 'model', 'budget', 'max_output_tokens', 'reserved_cost_usd', 'input_tokens',
    'output_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens', 'pricing',
    'cost_usd', 'error_code', 'error_message', 'started_at', 'finished_at', 'agent_definition_id', 'previous_run_id',
    'wants_title', 'answer', 'stop_requested_at', 'stop_reason', 'stop_budget_period', 'purposes', 'passed_tools',
    'knowledge_modules', 'compacted', 'retry_wait_seconds', 'retry_attempt', 'step_reserved_sum_usd',
    'step_cost_sum_usd', 'step_usage_sum',
])]
class AiRun extends Model
{
    /** @use HasFactory<AiRunFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind_type' => AiKindType::class,
            'status' => AiRunStatus::class,
            'source_record_ids' => 'array',
            'audio_seconds' => 'decimal:3',
            'max_output_tokens' => 'integer',
            'reserved_cost_usd' => 'decimal:6',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_input_tokens' => 'integer',
            'cache_write_input_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'pricing' => 'array',
            'cost_usd' => 'decimal:6',
            'error_code' => AiRunErrorCode::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'wants_title' => 'boolean',
            'answer' => AiConversationAnswer::class,
            'stop_requested_at' => 'datetime',
            'stop_reason' => AiRunStopReason::class,
            'purposes' => 'array',
            'passed_tools' => 'array',
            'knowledge_modules' => 'array',
            'compacted' => 'boolean',
            'retry_wait_seconds' => 'integer',
            'retry_attempt' => 'integer',
            'step_reserved_sum_usd' => 'decimal:6',
            'step_cost_sum_usd' => 'decimal:6',
            'step_usage_sum' => 'array',
        ];
    }

    /**
     * 依頼者。
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /**
     * 文章・文字起こしの行の共通の 1 つの接続先とモデル(会話の型の行は持たない = 用途ごと。文章・文字起こしの行に値があることは
     * 型ごとの列の組の検査制約が保証する)。
     *
     * @return array{string, string} 接続先・モデル
     *
     * @throws LogicException 値が無いとき(作りの誤り)
     */
    public function providerAndModel(): array
    {
        if ($this->provider === null || $this->model === null) {
            throw new LogicException("AI の実行 {$this->id} に共通の接続先とモデルが無い(会話の型の実行は用途ごとに持つ)");
        }

        return [$this->provider, $this->model];
    }

    /**
     * エージェントの定義(会話の型だけ)。
     *
     * @return BelongsTo<AiAgentDefinition, $this>
     */
    public function agentDefinition(): BelongsTo
    {
        return $this->belongsTo(AiAgentDefinition::class, 'agent_definition_id');
    }

    /**
     * 前の実行(会話の型だけ。利用者の操作が続ける実行。会話の最初の文は null)。
     *
     * @return BelongsTo<AiRun, $this>
     */
    public function previousRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_run_id');
    }

    /**
     * 送った文と応答の原文(保存期間を過ぎて消した後は null)。
     *
     * @return HasOne<AiRunText, $this>
     */
    public function text(): HasOne
    {
        return $this->hasOne(AiRunText::class);
    }

    /**
     * ステップ(会話の型だけ。設計/AI/実行の記録.md 2.3)。
     *
     * @return HasMany<AiRunStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(AiRunStep::class);
    }

    /**
     * 道具の呼び出し(会話の型だけ。設計/AI/実行の記録.md 2.4)。
     *
     * @return HasMany<AiToolCall, $this>
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(AiToolCall::class);
    }
}
