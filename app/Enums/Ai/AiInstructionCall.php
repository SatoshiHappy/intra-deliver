<?php

namespace App\Enums\Ai;

/**
 * 指示の組み立ての口が組む呼び出しの種類(docs の 設計/AI/設定とプロンプト/README.md 11.4 の「受け取る物」)。組む単位で、接続先とモデルを選ぶ
 * 用途({@see AiConversationPurpose})とは別の語。
 */
enum AiInstructionCall: string
{
    /** 会話のステップ */
    case ConversationStep = 'conversation_step';

    /** 要約 */
    case Compaction = 'compaction';

    /** 要約の予備 */
    case CompactionFallback = 'compaction_fallback';

    /** 会話の題 */
    case Title = 'title';

    /** ファイルを作る */
    case CreateFile = 'create_file';

    /** 送る用途(要約と要約の予備は会話の用途で送る = docs の 設計/AI/実行と費用/接続先と費用.md 5 節) */
    public function purpose(): AiConversationPurpose
    {
        return match ($this) {
            self::ConversationStep, self::Compaction, self::CompactionFallback => AiConversationPurpose::Conversation,
            self::Title => AiConversationPurpose::Title,
            self::CreateFile => AiConversationPurpose::CreateFile,
        };
    }
}
