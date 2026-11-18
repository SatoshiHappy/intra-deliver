<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;
use App\Enums\Ai\AiInstructionTemplate;
use DateTimeImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Tool;
use LogicException;

/**
 * 約束が依れる物(会話の保存の項目と定型の列挙・道具の約束・接続先の型・JSON の形・Laravel の約束・PHP の標準・約束の中)
 */
final class AllowedContract
{
    /**
     * @param  array<string, Type>  $schema
     * @param  Arrayable<string, mixed>|null  $extra
     */
    public function __construct(
        public ConversationItem $item,
        public AiInstructionTemplate $template,
        public Tool $tool,
        public TextProvider $provider,
        public array $schema,
        public ?Arrayable $extra,
        public DateTimeImmutable $at,
        public SendKind $kind,
    ) {}

    public function fail(): never
    {
        throw new LogicException('x');
    }
}
