<?php

namespace App\Actions\Ai\Core;

/**
 * 送る前の知らせ(docs の 設計/AI/会話の中核の口と受け手.md 13.3。確かめの口が続けるを返した後、送信の前)。
 */
final readonly class BeforeSendNotice implements CoreNotice
{
    /**
     * @param  int  $stepId  確かめの口が返したステップの行の番号
     * @param  int  $attempt  同じ送信の何回目か(1 から)
     * @param  int|null  $retryOfStepId  送り直しでは元のステップの番号
     * @param  int  $estimate  送る量の見積もり(設計/AI/エージェントの実行/途中の表示と上限.md 9.9)
     */
    public function __construct(
        public int $stepId,
        public SendKind $kind,
        public int $attempt,
        public ?int $retryOfStepId,
        public int $estimate,
    ) {}
}
