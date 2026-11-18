<?php

namespace App\Actions\Ai\Core;

use App\Enums\Ai\AiInstructionTemplate;

/**
 * 中核の中の値の型(保存しない物。作る者 = docs の 設計/AI/会話の中核.md 13.2)。送る履歴に会話の保存の項目と並べて置き、送るときに
 * ループが使う文の口で今の版の文に組む。
 */
final readonly class TransientText
{
    private function __construct(public AiInstructionTemplate $template) {}

    /** 続きを書かせる文(利用者の文として送る) */
    public static function outputContinuation(): self
    {
        return new self(AiInstructionTemplate::OutputContinuation);
    }

    /** 途切れた後に続ける旨の文(利用者の文として送る = 設計/AI/エージェントの実行/途中の表示と上限.md 9.8) */
    public static function resumeAfterCut(): self
    {
        return new self(AiInstructionTemplate::ResumeAfterCut);
    }

    /** 応答が無い旨の印(モデルの文として送る = 設計/AI/エージェントの実行/途中の表示と上限.md 9.8) */
    public static function noResponse(): self
    {
        return new self(AiInstructionTemplate::NoResponse);
    }

    /**
     * 要約の依頼が長すぎて古い往復を落とした印(落とした後の先頭がモデルの文のとき先頭に置く。利用者の文として送る =
     * 設計/AI/エージェントの実行/途中の表示と上限.md 9.9)。
     */
    public static function oldTurnsDropped(): self
    {
        return new self(AiInstructionTemplate::OldTurnsDropped);
    }

    /** モデルの文として送るか(偽 = 利用者の文) */
    public function isFromModel(): bool
    {
        return $this->template === AiInstructionTemplate::NoResponse;
    }
}
