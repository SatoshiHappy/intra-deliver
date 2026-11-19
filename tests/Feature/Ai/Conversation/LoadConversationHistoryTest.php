<?php

namespace Tests\Feature\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Conversation\LoadConversationHistory;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\AiRunErrorCode;
use App\Models\Ai\AiRun;
use Carbon\CarbonImmutable;
use Tests\Feature\Ai\FakeConversationStore;
use Tests\Feature\Ai\RecordingConversationKind;
use Tests\Support\TransactionalTestCase;

/**
 * 会話のジョブの 4(docs の 設計/AI/エージェントの実行/README.md 9.1、直し = 設計/AI/エージェントの実行/途中の表示と上限.md 9.8、
 * 日付の文の値 = 設計/AI/会話の中核.md 13.2)。
 */
final class LoadConversationHistoryTest extends TransactionalTestCase
{
    private FakeConversationStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        FakeConversationStore::createTables();
        $this->store = new FakeConversationStore;
    }

    /** 最初の実行の始めの日は東京の日(UTC 15:30 は東京の翌日) */
    public function test_history_and_the_first_run_date(): void
    {
        $first = $this->endedRun(null, '2026-11-18 15:30:00+00');
        $second = $this->endedRun($first, '2026-11-20 01:00:00+00');
        $run = $this->currentRun($second, [
            ConversationItem::userText('一'),
            self::text('r1', '答え'),
            ConversationItem::userText('二'),
        ]);

        [$history, $values] = app(LoadConversationHistory::class)->execute($run, $this->store->read($run->id) ?? []);

        $this->assertSame(['user_text', 'model_text', 'user_text'], self::types($history));
        $this->assertSame('2026-11-19', $values->firstRunDate);
    }

    /** 前の実行が worker が落ちて終わったら直しを当て、今の実行の利用者の文は直しの後につなぐ */
    public function test_the_repair_applies_before_the_current_items(): void
    {
        $previous = $this->endedRun(null, '2026-11-18 01:00:00+00', AiRunErrorCode::WorkerLost);
        $calls = ConversationItem::modelText('r1', [ConversationItem::toolCallBlock('c1', 'search', [])]);
        $run = $this->currentRun($previous, [ConversationItem::userText('一'), $calls, ConversationItem::userText('二')]);

        [$history] = app(LoadConversationHistory::class)->execute($run, $this->store->read($run->id) ?? []);

        $this->assertSame(['user_text', 'no_response', 'user_text'], self::types($history));
    }

    private function endedRun(?AiRun $previous, string $startedAt, ?AiRunErrorCode $error = null): AiRun
    {
        $factory = AiRun::factory()->conversation();
        $factory = $error === null ? $factory->running() : $factory->failed($error);

        return $factory->create([
            'kind' => RecordingConversationKind::CODE,
            'previous_run_id' => $previous?->id,
            'started_at' => CarbonImmutable::parse($startedAt),
        ]);
    }

    /**
     * @param  list<ConversationItem>  $items
     */
    private function currentRun(AiRun $previous, array $items): AiRun
    {
        $run = AiRun::factory()->conversation()->running()->create([
            'kind' => RecordingConversationKind::CODE,
            'previous_run_id' => $previous->id,
        ]);
        $conversation = $this->store->startConversation($previous->id);
        $this->store->continueConversation($conversation, $run->id);
        $this->store->append($run->id, $items);

        return $run;
    }

    private static function text(string $responseId, string $text): ConversationItem
    {
        return ConversationItem::modelText($responseId, [ConversationItem::textBlock($text)]);
    }

    /**
     * @param  list<ConversationItem|TransientText>  $items
     * @return list<string>
     */
    private static function types(array $items): array
    {
        return array_map(static fn (ConversationItem|TransientText $item): string => $item instanceof TransientText
            ? ($item->isFromModel() ? 'no_response' : 'resume_after_cut')
            : $item->type->value, $items);
    }
}
