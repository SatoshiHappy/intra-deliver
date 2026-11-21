<?php

namespace App\Queries\Ai\Api;

/**
 * エージェントの定義 1 件(docs の 設計/AI/設定とプロンプト/README.md 11.1)。
 */
final readonly class AgentDefinitionView
{
    /**
     * @param  list<string>  $tools  使える道具の名前
     * @param  list<string>  $knowledge  ナレッジの業務モジュールのコード
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $instructions,
        public array $tools,
        public array $knowledge,
    ) {}
}
