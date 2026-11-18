<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;

/**
 * 道具を動かす口の動かすの返り値(docs の 設計/AI/会話の中核の口と受け手.md 13.3): 道具の結果の項目(断りも失敗も、受け手が返した
 * 項目のまま。中核は分けない)・問う・会話が無い。
 */
final readonly class ToolRun
{
    private function __construct(
        public ToolRunAnswer $answer,
        public ?ConversationItem $result = null,
    ) {}

    /** 道具の結果の項目 */
    public static function result(ConversationItem $result): self
    {
        return new self(ToolRunAnswer::Result, $result);
    }

    /** 問う(実行を確認待ちで終える) */
    public static function asks(): self
    {
        return new self(ToolRunAnswer::Asks);
    }

    /** 会話が無い */
    public static function noConversation(): self
    {
        return new self(ToolRunAnswer::NoConversation);
    }
}
