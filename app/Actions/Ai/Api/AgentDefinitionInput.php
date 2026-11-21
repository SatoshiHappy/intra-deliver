<?php

namespace App\Actions\Ai\Api;

/**
 * エージェントの定義を作る・直すときの値(docs の 設計/AI/設定とプロンプト/README.md 11.1)。
 */
final readonly class AgentDefinitionInput
{
    /**
     * @param  list<string>  $tools  使える道具の名前
     * @param  list<string>  $knowledge  ナレッジの業務モジュールのコード
     */
    public function __construct(
        public string $name,
        public string $instructions,
        public array $tools,
        public array $knowledge,
    ) {}
}
