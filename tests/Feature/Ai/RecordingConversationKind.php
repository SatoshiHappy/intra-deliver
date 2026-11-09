<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationEnded;
use App\Actions\Ai\Api\ConversationFailed;
use App\Actions\Ai\Api\ConversationKind;
use App\Actions\Ai\Api\ConversationStore;
use Closure;

/**
 * テストの会話の型の種類(コード test.conversation。① の本物の代わり)。completed と failed に渡された値を覚え、会話の保存の口は偽物
 * {@see FakeConversationStore} を返す。failed の中身はテストが差し替える。
 */
final class RecordingConversationKind implements ConversationKind
{
    public const CODE = 'test.conversation';

    /** @var (Closure(ConversationFailed): void)|null failed の中で走らせる処理 */
    public ?Closure $onFailed = null;

    /** @var list<ConversationEnded> */
    public array $completed = [];

    /** @var list<ConversationFailed> */
    public array $failed = [];

    public function __construct(private FakeConversationStore $store = new FakeConversationStore) {}

    public function code(): string
    {
        return self::CODE;
    }

    public function store(): ConversationStore
    {
        return $this->store;
    }

    public function completed(ConversationEnded $run): void
    {
        $this->completed[] = $run;
    }

    public function failed(ConversationFailed $run): void
    {
        $this->failed[] = $run;

        if ($this->onFailed !== null) {
            ($this->onFailed)($run);
        }
    }
}
