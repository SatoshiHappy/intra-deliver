<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationStore;
use App\Models\Ai\AiRun;
use Illuminate\Support\Facades\DB;
use Tests\Support\TransactionalTestCase;

/**
 * 会話の保存の口の約束({@see ConversationStoreContract})を、テストの中だけの偽物の口 {@see FakeConversationStore} で流す
 * (約束のテストが偽物で通ることで、③ の約束のテストの偽物の口が約束を守ることを確かめる)。
 */
final class FakeConversationStoreTest extends TransactionalTestCase
{
    use ConversationStoreContract;

    private FakeConversationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        FakeConversationStore::createTables();
        $this->store = new FakeConversationStore;
    }

    protected function conversationStore(): ConversationStore
    {
        return $this->store;
    }

    protected function runInNewConversation(): int
    {
        $runId = AiRun::factory()->conversation()->create()->id;
        $this->store->startConversation($runId);

        return $runId;
    }

    protected function nextRunInSameConversation(int $runId): int
    {
        $next = AiRun::factory()->conversation()->create(['previous_run_id' => $runId])->id;
        $this->store->continueConversation($this->conversationOf($runId), $next);

        return $next;
    }

    protected function runWithoutConversation(): int
    {
        return AiRun::factory()->conversation()->create()->id;
    }

    protected function deleteConversationOf(int $runId): void
    {
        $this->store->deleteConversation($this->conversationOf($runId));
    }

    protected function failNextAppendAfter(int $writes): void
    {
        $this->store->failAfterWrites = $writes;
    }

    /** 実行の会話(偽物の対応の表から引く) */
    private function conversationOf(int $runId): int
    {
        return (int) DB::table('test_conversation_runs')->where('ai_run_id', $runId)->value('conversation_id');
    }
}
