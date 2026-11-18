<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;

/**
 * 道具を動かす口の探すの返り値(docs の 設計/AI/会話の中核の口と受け手.md 13.3): 有る・無い(無い道具の結果の項目を添える)・会話が無い。
 */
final readonly class ToolLookup
{
    private function __construct(
        public ToolLookupAnswer $answer,
        public ?ConversationItem $missingResult = null,
    ) {}

    /** 有る */
    public static function found(): self
    {
        return new self(ToolLookupAnswer::Found);
    }

    /** 無い(受け手が書いた無い道具の結果の項目。中核はそれを道具の結果として扱う) */
    public static function missing(ConversationItem $result): self
    {
        return new self(ToolLookupAnswer::Missing, $result);
    }

    /** 会話が無い(包みの中の会話の保存の足すが無しを返した) */
    public static function noConversation(): self
    {
        return new self(ToolLookupAnswer::NoConversation);
    }
}
