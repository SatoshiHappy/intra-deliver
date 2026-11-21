<?php

namespace App\Actions\Ai\Api;

use App\Enums\Ai\AiToolNature;

/**
 * 定義の使える道具に選べる道具 1 つ(docs の 設計/AI/公開の口.md 3 節の定義の読み書きの行)。
 */
final readonly class AgentToolCandidate
{
    /**
     * @param  list<string>|null  $targetModules  対象の業務モジュールのコード(null = 定義のナレッジを使う汎用の道具)
     */
    public function __construct(
        public string $name,
        public string $displayName,
        public AiToolNature $nature,
        public ?array $targetModules,
    ) {}
}
