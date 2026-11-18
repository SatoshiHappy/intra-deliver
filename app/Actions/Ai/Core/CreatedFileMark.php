<?php

namespace App\Actions\Ai\Core;

/**
 * 要約の文に足す作ったファイル 1 つの名前と印(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.9)。
 */
final readonly class CreatedFileMark
{
    public function __construct(
        public string $name,
        public string $marker,
    ) {}
}
