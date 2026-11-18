<?php

namespace App\Actions\Ai\Core;

/**
 * 量を返す口の返す物(docs の 設計/AI/会話の中核の口と受け手.md 13.3。モデルの値から決めた要約の発動の量と止める量 = 設計/AI/エージェントの実行/途中の表示と上限.md 9.9)。単位はトークン。
 */
final readonly class CompactThresholds
{
    public function __construct(
        public int $compactAt,
        public int $stopAt,
    ) {}
}
