<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationEnded;
use App\Actions\Ai\Api\ConversationFailed;
use App\Actions\Ai\Api\ConversationKind;
use App\Actions\Ai\Api\ConversationStore;

/**
 * 文章の種類 {@see RecordingPromptKind} と同じコードの会話の型の種類(コードが 3 つの型をまたいで一意かを確かめる)。
 */
final class SameCodeConversationKind implements ConversationKind
{
    public function code(): string
    {
        return RecordingPromptKind::CODE;
    }

    public function store(): ConversationStore
    {
        return new FakeConversationStore;
    }

    public function completed(ConversationEnded $run): void {}

    public function failed(ConversationFailed $run): void {}
}
