<?php

namespace UpdateCheck;

/**
 * 固定したイメージごとの更新の調べの結果(ラベル → 結果)と、調べられなかったもの(ラベル → 理由)。
 */
final readonly class ImageUpdateResults
{
    /**
     * @param  array<string, ImageUpdate>  $updates
     * @param  array<string, string>  $failures
     */
    public function __construct(
        public array $updates,
        public array $failures,
    ) {}

    public function newBase(string $label): ?string
    {
        return ($this->updates[$label] ?? null)?->newBase();
    }
}
