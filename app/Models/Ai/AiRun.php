<?php

namespace App\Models\Ai;

use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Master\User;
use Database\Factories\Ai\AiRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * AI の実行(LLM の呼び出し 1 回)。表 ai_runs(docs の 設計/AI/README.md 2.1)。記録の表で、業務レコードを消しても残る。
 * 使用量・単価・費用は分かったときに一緒に書き、分からない失敗は null のまま(列の組は検査制約が止める)。更新日時の列を持たない(状態の時刻は started_at・finished_at)。
 */
#[Fillable(['kind', 'status', 'requester_user_id', 'selected_department_id', 'record_id', 'original_record_id', 'record_module_code', 'source_record_ids', 'provider', 'model', 'budget', 'max_output_tokens', 'reserved_cost_usd', 'input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens', 'pricing', 'cost_usd', 'error_code', 'error_message', 'started_at', 'finished_at'])]
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
            'status' => AiRunStatus::class,
            'source_record_ids' => 'array',
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
     * 送った文と応答の原文(保存期間を過ぎて消した後は null)。
     *
     * @return HasOne<AiRunText, $this>
     */
    public function text(): HasOne
    {
        return $this->hasOne(AiRunText::class);
    }
}
