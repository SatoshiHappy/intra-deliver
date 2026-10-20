<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\CompletedRun;
use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\PromptKind;
use App\Models\Ai\AiRunText;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use RuntimeException;

/**
 * テストの種類(コード test.throwing)。complete で送った文の行を書き換えてから例外を投げる(書き込みが巻き戻るか)。書き込みの口は使わない。
 */
final class ThrowingPromptKind implements PromptKind
{
    public const CODE = 'test.throwing';

    public const MESSAGE = '出力を解釈できない';

    /** complete が書き換えて、巻き戻ることを確かめる値 */
    public const WRITTEN = 'complete が書いた';

    public function code(): string
    {
        return self::CODE;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['summary' => $schema->string()->required()];
    }

    public function writesTo(): ?string
    {
        return null;
    }

    public function complete(CompletedRun $run): void
    {
        AiRunText::query()->whereKey($run->runId)->update(['system_text' => self::WRITTEN]);

        throw new RuntimeException(self::MESSAGE);
    }

    public function failed(FailedRun $run): void {}
}
