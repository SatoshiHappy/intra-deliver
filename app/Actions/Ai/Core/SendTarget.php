<?php

namespace App\Actions\Ai\Core;

use Laravel\Ai\Contracts\Providers\TextProvider;

/**
 * 送る先(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「1 回の送信の受け取る物」の接続先・モデル・性質・時間の上限)。
 */
final readonly class SendTarget
{
    /**
     * @param  TextProvider  $provider  接続先(開けない値として受け渡す = 設計/AI/会話の中核.md 13.1)
     * @param  string  $providerName  接続先の名前(laravel/ai の接続先の名前と同じ値。生の状態を渡すかの比べと、応答の終わりの項目に使う = 13.2)
     * @param  int  $timeoutSeconds  呼び出しの時間の上限(流れの全体に掛かる = 設計/AI/会話の中核の口と受け手.md 13.3)
     */
    public function __construct(
        public TextProvider $provider,
        public string $providerName,
        public ModelSpec $model,
        public ProviderTraits $traits,
        public int $timeoutSeconds,
    ) {}
}
