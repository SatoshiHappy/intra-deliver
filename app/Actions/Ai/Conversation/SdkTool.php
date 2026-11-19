<?php

namespace App\Actions\Ai\Conversation;

use App\Actions\Ai\ToolCalls\GrantedTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;
use Stringable;

/**
 * 渡す道具を `laravel/ai` の道具の約束の形に写す(docs の 設計/AI/会話の中核.md 13.1 の「道具」)。中核は道具を 1 ステップの口へ渡すだけで
 * 動かさない(動かすのは道具を動かす口 = 包み)ので、SDK から動かす道は作りの誤り。
 */
final readonly class SdkTool implements Tool
{
    public function __construct(private GrantedTool $granted) {}

    /** SDK が道具の名前を引く口(`ToolNameResolver`) */
    public function name(): string
    {
        return $this->granted->tool->name();
    }

    public function description(): string
    {
        return $this->granted->tool->description($this->granted->scope);
    }

    /**
     * @throws LogicException いつも(道具は包みで動かす)
     */
    public function handle(Request $request): Stringable|string
    {
        throw new LogicException("道具 {$this->name()} は道具を動かす口で動かす");
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->granted->tool->schema($schema);
    }
}
