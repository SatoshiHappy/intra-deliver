<?php

namespace App\Models\Ai;

use Database\Factories\Ai\AiToolCallTextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 道具の呼び出しの期限で消す値(呼び出し 1 回に 1 行。主キー = 道具の行)。表 ai_tool_call_texts(docs の 設計/AI/実行の記録.md 2.4)。
 * 作ってから保存期間を過ぎた行を消す(設計/AI/データと失敗と検査.md 6 節)。元の引数はモデルが渡した値のままで、object でないこともある
 * (形の確かめに落ちた呼び出しも記録に残す = 設計/AI/エージェントの実行/道具の包みと止める.md 9.4)。
 */
#[Fillable(['ai_tool_call_id', 'arguments', 'result_text', 'confirmation', 'record_names'])]
class AiToolCallText extends Model
{
    /** @use HasFactory<AiToolCallTextFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $primaryKey = 'ai_tool_call_id';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'json',
            'confirmation' => 'array',
            'record_names' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiToolCall, $this>
     */
    public function toolCall(): BelongsTo
    {
        return $this->belongsTo(AiToolCall::class, 'ai_tool_call_id');
    }
}
