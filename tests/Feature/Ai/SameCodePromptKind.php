<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\CompletedRun;
use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\PromptKind;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * 文字起こしの種類 {@see RecordingTranscriptionKind} と同じコードの文章の種類(コードが 2 つの型をまたいで一意かを確かめる)。
 */
final class SameCodePromptKind implements PromptKind
{
    public function code(): string
    {
        return RecordingTranscriptionKind::CODE;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['summary' => $schema->string()->required()];
    }

    public function writesTo(): ?string
    {
        return null;
    }

    public function complete(CompletedRun $run): void {}

    public function failed(FailedRun $run): void {}
}
