<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;

/**
 * 送った後の知らせ(docs の 設計/AI/会話の中核の口と受け手.md 13.3): 使用量と終わりの理由、または失敗した試しの誤りの種類(誤りの状態)と
 * 流れの途中で切れたか。
 *
 * @phpstan-import-type Usage from ConversationItem
 */
final readonly class AfterSendNotice implements CoreNotice
{
    /**
     * @param  Usage|null  $usage  使用量(届いたとき)
     * @param  ResponseFinish|null  $finish  終わりの理由(応答が終わりまで届いたとき)
     * @param  int|null  $errorStatus  失敗した試しの誤りの状態(HTTP の状態。無ければ null)
     */
    public function __construct(
        public int $stepId,
        public ?array $usage,
        public ?ResponseFinish $finish,
        public ?int $errorStatus,
        public bool $cutMidStream,
    ) {}
}
