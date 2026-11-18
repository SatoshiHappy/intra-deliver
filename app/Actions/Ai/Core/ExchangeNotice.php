<?php

namespace App\Actions\Ai\Core;

/**
 * 送った要求と応答の原文の知らせ(docs の 設計/AI/会話の中核の口と受け手.md 13.3。実行の記録の原文 = 設計/AI/エージェントの実行/途中の表示と上限.md 9.6)。応答が届かなかった試しでは
 * 応答は null。
 */
final readonly class ExchangeNotice implements CoreNotice
{
    /**
     * @param  array<array-key, mixed>  $request
     * @param  array<array-key, mixed>|null  $response
     */
    public function __construct(
        public int $stepId,
        public array $request,
        public ?array $response,
    ) {}
}
