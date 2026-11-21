<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;
use DateTimeImmutable;

/**
 * 道具を動かす口の動かすの返り値(docs の 設計/AI/会話の中核の口と受け手.md 13.3): 道具の結果の項目(断りも失敗も、受け手が返した
 * 項目のまま。中核は分けない)・問う・会話が無い。
 */
final readonly class ToolRun
{
    private function __construct(
        public ToolRunAnswer $answer,
        public ?ConversationItem $result = null,
        public ?DateTimeImmutable $lastSendFinishedAt = null,
    ) {}

    /**
     * 道具の結果の項目。$lastSendFinishedAt = 道具の中で成功した最後の送信の完了の時刻(ファイルを作るの中の呼び出し。無ければ null。
     * 思考の文脈の管理の最後の完了の時刻 = 設計/AI/会話の中核.md 13.2)。
     */
    public static function result(ConversationItem $result, ?DateTimeImmutable $lastSendFinishedAt = null): self
    {
        return new self(ToolRunAnswer::Result, $result, $lastSendFinishedAt);
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
