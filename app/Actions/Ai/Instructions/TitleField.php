<?php

namespace App\Actions\Ai\Instructions;

/**
 * 会話の題の答えの 1 欄(docs の 設計/AI/設定とプロンプト/README.md 11.4 の「題の答えの形」)。構造化の出力の形に組むのは呼び手。
 */
final readonly class TitleField
{
    public function __construct(
        public string $name,
        public string $description,
    ) {}
}
