<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\Core\ProviderTraits;
use App\Actions\Ai\Core\SendKind;
use App\Enums\Ai\AiConversationPurpose;

/**
 * 送る前の確かめの関数に渡す物(docs の 設計/AI/エージェントの実行/README.md 9.2 の「送る前の確かめの関数」)。量は中核が確かめの口に
 * 渡した値のまま(数える範囲 = 設計/AI/会話の中核の口と受け手.md 13.3)。
 */
final readonly class SendCheckInput
{
    /**
     * @param  int  $chars  送る文の全体の字数
     * @param  int  $outputLimit  その送信の出力の上限
     * @param  int  $sendLimit  その送信の送る量の上限(トークン)
     * @param  bool  $timedOut  実行の時間の上限を超えたか({@see RunClock::exceeded()})
     */
    public function __construct(
        public int $runId,
        public AiConversationPurpose $purpose,
        public SendKind $kind,
        public string $provider,
        public string $model,
        public ProviderTraits $traits,
        public int $chars,
        public int $outputLimit,
        public int $sendLimit,
        public bool $timedOut,
    ) {}
}
