<?php

namespace UpdateCheck;

/**
 * 確認できなかった 1 件(何を・なぜ)。
 */
final readonly class Unconfirmed
{
    public function __construct(
        public string $subject,
        public string $reason,
    ) {}
}
