<?php

namespace Tests\Unit\Actions\Ai\Core\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;

/**
 * 中核のテストの渡す道具(名前と、必須の文字の引数 module の形だけを持つ。中核は動かさない)。
 */
final readonly class NamedTool implements Tool
{
    public function __construct(private string $name) {}

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return '';
    }

    /**
     * @throws LogicException いつも(中核は道具を動かさない)
     */
    public function handle(Request $request): string
    {
        throw new LogicException('中核のテストの道具は動かさない');
    }

    public function schema(JsonSchema $schema): array
    {
        return ['module' => $schema->string()->required()];
    }
}
