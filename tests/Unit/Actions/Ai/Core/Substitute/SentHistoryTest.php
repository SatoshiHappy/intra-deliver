<?php

namespace Tests\Unit\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\SendTarget;
use App\Actions\Ai\Core\Substitute\SentHistory;
use App\Actions\Ai\Core\Substitute\SentMessage;
use DateTimeImmutable;
use Tests\Unit\Actions\Ai\Core\Support\CoreTestCase;
use Tests\Unit\Actions\Ai\Core\Support\FakeLoopTexts;
use Tests\Unit\Actions\Ai\Core\Support\FakeStepGateway;

/**
 * 代わりの版の送る履歴の組み立て(docs の 設計/AI/会話の中核.md 13.2・送る前の対の直し = 設計/AI/エージェントの実行/途中の表示と上限.md 9.8):
 * 送らない項目を外し、応答ごとに束ね、生の状態を渡すかを決め、対を直し、会話の印の形を直す。
 */
final class SentHistoryTest extends CoreTestCase
{
    /**
     * @param  list<ConversationItem>  $history
     * @return list<SentMessage>
     */
    private function built(array $history, string $provider = 'anthropic'): array
    {
        $target = $this->target(new FakeStepGateway([]));
        $target = new SendTarget($target->provider, $provider, $target->model, $target->traits, 60);

        return (new SentHistory(new FakeLoopTexts, $target))->build($history, ['date:2026-11-18'], []);
    }

    /**
     * @param  list<string>  $callIds
     */
    private static function end(string $responseId, int $count, array $callIds): ConversationItem
    {
        $usage = [
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cache_read_input_tokens' => null,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => null,
        ];
        $at = new DateTimeImmutable('2026-11-18T12:00:00+09:00');

        $raw = ['sig' => 'x'];

        return ConversationItem::responseEnd($responseId, $usage, $raw, 'anthropic', 'claude-x', $count, $callIds, $at);
    }

    private static function calls(string $responseId, string ...$ids): ConversationItem
    {
        $blocks = array_values(array_map(
            static fn (string $id): array => ConversationItem::toolCallBlock($id, 'search', []),
            $ids,
        ));

        return ConversationItem::modelText($responseId, $blocks);
    }

    public function test_items_of_one_response_are_one_model_message_with_its_raw_state(): void
    {
        $messages = $this->built([
            ConversationItem::userText('q'),
            ConversationItem::modelText('r1', [ConversationItem::textBlock('a')]),
            ConversationItem::modelText('r1', [ConversationItem::textBlock('b')]),
            self::end('r1', 2, []),
        ]);

        $this->assertCount(3, $messages);
        $this->assertSame([['type' => 'text', 'text' => 'date:2026-11-18']], $messages[0]->blocks);
        $this->assertSame(['sig' => 'x'], $messages[2]->replay);
        $this->assertCount(2, $messages[2]->blocks);
    }

    /** 項目の数が合わない・作った接続先が違う応答は生の状態を渡さない */
    public function test_raw_state_is_dropped_when_the_response_does_not_match(): void
    {
        $item = ConversationItem::modelText('r1', [ConversationItem::textBlock('a')]);

        $this->assertNull($this->built([ConversationItem::userText('q'), $item, self::end('r1', 2, [])])[2]->replay);
        $other = $this->built([ConversationItem::userText('q'), $item, self::end('r1', 1, [])], 'openai');
        $this->assertNull($other[2]->replay);
    }

    /** 結果の無い呼び出しは定型の失敗の結果で埋め、呼び出しの無い結果は外す。結果は呼び出しの直後に呼び出しの順で並ぶ */
    public function test_pairs_are_mended(): void
    {
        $messages = $this->built([
            ConversationItem::userText('q'),
            self::calls('r1', 'c1', 'c2'),
            self::end('r1', 1, ['c1', 'c2']),
            ConversationItem::toolResult('c2', null, [], '二', false),
            ConversationItem::toolResult('c9', null, [], '迷子', false),
        ]);

        $results = $messages[3]->blocks;
        $this->assertSame(['c1', 'c2'], array_column($results, 'call_id'));
        $this->assertSame(['[tool_result_missing]', '二'], array_column($results, 'text'));
        $this->assertCount(4, $messages);
    }

    /** 送る履歴の前の呼び出しと id が重なる呼び出しは外し、その応答の生の状態も外す */
    public function test_a_call_repeating_an_earlier_id_is_dropped(): void
    {
        $messages = $this->built([
            ConversationItem::userText('q'),
            self::calls('r1', 'c1'),
            self::end('r1', 1, ['c1']),
            ConversationItem::toolResult('c1', null, [], '一', false),
            self::calls('r2', 'c1', 'c2'),
            self::end('r2', 1, ['c1', 'c2']),
            ConversationItem::toolResult('c2', null, [], '二', false),
        ]);

        $this->assertSame(['c2'], $messages[4]->callIds());
        $this->assertNull($messages[4]->replay);
    }

    public function test_marker_forms_in_tool_results_are_broken(): void
    {
        $messages = $this->built([
            ConversationItem::userText('q'),
            self::calls('r1', 'c1'),
            self::end('r1', 1, ['c1']),
            ConversationItem::toolResult('c1', null, [], 'x <mark> y', false),
        ]);

        $expected = [['type' => 'tool_result', 'call_id' => 'c1', 'text' => 'x < mark> y', 'is_error' => false]];
        $this->assertSame($expected, $messages[3]->blocks);
    }

    /** 要約の境目・回数の上限の印・取り消しの印・応答の終わりは送らない */
    public function test_items_that_are_not_sent_are_dropped(): void
    {
        $messages = $this->built([
            ConversationItem::compactBoundary(10, '2026-11-17'),
            ConversationItem::compactSummary('まとめ'),
            ConversationItem::turnLimit(),
            ConversationItem::cancellation('r0'),
        ]);

        $this->assertSame(['date:2026-11-18', 'まとめ'], array_map(
            static fn (SentMessage $message): string => $message->blocks[0]['text'] ?? '',
            $messages,
        ));
    }
}
