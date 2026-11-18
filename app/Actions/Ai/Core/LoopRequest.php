<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;
use Laravel\Ai\Contracts\Tool;

/**
 * ループの受け取る物(docs の 設計/AI/会話の中核の口と受け手.md 13.3)。指示の文は送信の種別ごとにループが使う文の口から受ける
 * ({@see LoopTexts::instructions()}。要約・要約の予備で文が替わり、先頭の日付の文の値が履歴で決まるため)。
 */
final readonly class LoopRequest
{
    /**
     * @param  list<ConversationItem|TransientText>  $history  送る履歴(繋ぎが読み込みの直し = 設計/AI/エージェントの実行/途中の表示と上限.md 9.8 を当てた後)
     * @param  list<Tool>  $tools  渡す道具
     * @param  array<value-of<SendKind>, array<string, mixed>>  $providerOptions  送信の種別ごとの要求に足す指定(無い種別は空)
     * @param  int  $maxTurns  回数の上限(設計/AI/エージェントの実行/途中の表示と上限.md 9.7)
     * @param  int  $progressSeconds  途中の文を書く間隔(設計/AI/エージェントの実行/途中の表示と上限.md 9.6。読む途中の確かめと
     *                                送り直しの待ちの刻み)
     * @param  list<CoreToolCall>  $preRunCalls  先に動かす呼び出し(「実行」と答えた承認待ちの呼び出し =
     *                                           設計/AI/エージェントの実行/README.md 9.3。最初の送信の前に動かす)
     */
    public function __construct(
        public SendTarget $target,
        public array $history,
        public array $tools,
        public bool $stream,
        public array $providerOptions,
        public int $maxTurns,
        public int $progressSeconds,
        public array $preRunCalls,
        public AddedTextValues $addedTexts,
        public LoopReceivers $receivers,
    ) {}
}
