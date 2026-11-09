<?php

namespace App\Models\Ai;

use Database\Factories\Ai\AiRunTextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * AI に送った文と応答の原文(実行 1 回に 1 行。主キー = 実行)。表 ai_run_texts(docs の 設計/AI/実行の記録.md 2.2)。
 * 作ってから保存期間を過ぎた行を消す(設計/AI/データと失敗と検査.md 6 節)。応答の原文は構造化出力の JSON の文字列で、応答が無ければ null。
 * 文章・文字起こしの行は 2 つの文を持つ(依頼の口が書く)。会話の型の行はシステムの文を持たず、利用者の文は文の実行だけが持ち、途中の文
 * (ステップの番号と文の列 = 設計/AI/エージェントの実行/途中の表示と上限.md 9.6)を持つ。
 */
#[Fillable(['ai_run_id', 'system_text', 'user_text', 'output_text', 'partial_text'])]
class AiRunText extends Model
{
    /** @use HasFactory<AiRunTextFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $primaryKey = 'ai_run_id';

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'partial_text' => 'array',
        ];
    }

    /**
     * 文章の行の送った 2 つの文(会話の型の行は持たないことがある。文章・文字起こしの行に値があることは依頼の口が守る)。
     *
     * @return array{string, string} システムの文・利用者の文
     *
     * @throws LogicException 値が無いとき(作りの誤り)
     */
    public function sentTexts(): array
    {
        if ($this->system_text === null || $this->user_text === null) {
            throw new LogicException("AI の実行 {$this->ai_run_id} の原文に送った 2 つの文が無い");
        }

        return [$this->system_text, $this->user_text];
    }

    /**
     * @return BelongsTo<AiRun, $this>
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }
}
