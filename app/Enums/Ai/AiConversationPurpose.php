<?php

namespace App\Enums\Ai;

/**
 * 会話の型の用途(docs の 設計/AI/実行の記録.md 2.1 の「用途ごとの接続先とモデル」。表 ai_run_steps の purpose と、設定の会話の用途の鍵)。
 * 値は DB の検査制約と同じ一覧。要約は会話の用途で送る(設計/AI/エージェントの実行/途中の表示と上限.md 9.9)。
 */
enum AiConversationPurpose: string
{
    /** 会話 */
    case Conversation = 'conversation';

    /** 会話の題 */
    case Title = 'title';

    /** ファイルを作る */
    case CreateFile = 'create_file';
}
