<?php

namespace App\Actions\Ai\Core;

/**
 * 知らせの口の返り値(docs の 設計/AI/会話の中核の口と受け手.md 13.3): 受けた・会話が無い(設計/AI/エージェントの実行/README.md 9.1 の会話の保存)・呼び出しの id の重なり
 * (設計/AI/実行の記録.md 2.4。確定したモデルの文の知らせだけ。重なった呼び出しは動かさず、結果も付けずにループを続ける)。
 */
final readonly class NoticeReply
{
    /**
     * @param  list<string>  $duplicateCallIds
     */
    private function __construct(
        public bool $conversationGone,
        public array $duplicateCallIds = [],
    ) {}

    /** 受けた */
    public static function accepted(): self
    {
        return new self(false);
    }

    /** 会話が無い */
    public static function noConversation(): self
    {
        return new self(true);
    }

    /**
     * 呼び出しの id の重なり(項目を会話の保存に書いたうえで、その項目の中の呼び出しのうち実行の鎖の前の記録と重なった物の id を返す。
     * 同じ応答の中の重なりは中核が見るので入れない)。
     *
     * @param  non-empty-list<string>  $callIds
     */
    public static function duplicateCalls(array $callIds): self
    {
        return new self(false, $callIds);
    }
}
