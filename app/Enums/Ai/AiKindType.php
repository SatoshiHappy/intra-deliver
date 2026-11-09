<?php

namespace App\Enums\Ai;

/**
 * AI の実行の種類の型(docs の 設計/AI/公開の口.md 3.1。表 ai_runs の kind_type と、設定 config/ai_runs.php の kinds の type)。値は DB の検査制約と同じ一覧。
 * 実行の行は依頼の口が種類の型を写して持ち、検査制約は型ごとに持つ列と状態をこの値で見る(登録は DB から引けないため = 設計/AI/実行の記録.md 2.1)。
 */
enum AiKindType: string
{
    /** 文章(LLM の呼び出し 1 回) */
    case Prompt = 'prompt';

    /** 文字起こし(音声 1 本の区切りごとの呼び出しの列) */
    case Transcription = 'transcription';

    /** 会話(利用者の 1 操作。接続先への送信 1 回 = ステップの列) */
    case Conversation = 'conversation';
}
