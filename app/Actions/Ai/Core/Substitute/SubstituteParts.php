<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\SendKind;
use App\Actions\Ai\Core\SendTarget;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Tool;

/**
 * 1 回の送信を組む材料(送る履歴は送る形に組んだ後の物)。
 */
final readonly class SubstituteParts
{
    /**
     * @param  list<SentMessage>  $messages
     * @param  list<Tool>  $tools
     * @param  array<string, Type>|null  $schema
     * @param  array<string, mixed>  $providerOptions
     * @param  int  $toolChars  道具の定義の字数({@see SubstitutePlans::toolChars()})
     * @param  int|null  $sendLimit  送る量の上限(無し = その送信で送る量。題 = 設計/AI/会話の中核の口と受け手.md 13.3)
     */
    public function __construct(
        public SendTarget $target,
        public SendKind $kind,
        public string $system,
        public array $messages,
        public array $tools,
        public ?array $schema,
        public bool $stream,
        public array $providerOptions,
        public bool $writesCache,
        public int $maxTokens,
        public ?int $sendLimit,
        public int $toolChars,
    ) {}
}
