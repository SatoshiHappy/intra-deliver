<?php

namespace Tests\Unit\Actions\Ai\Conversation;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Conversation\HistoryRepair;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\AiInstructionTemplate;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 読み込みの直し(docs の 設計/AI/エージェントの実行/途中の表示と上限.md 9.8)。
 */
final class HistoryRepairTest extends TestCase
{
    public function test_the_items_of_a_cancelled_response_are_dropped(): void
    {
        $repaired = HistoryRepair::repaired([
            ConversationItem::userText('問い'),
            self::text('r1', '途中'),
            self::end('r1'),
            ConversationItem::cancellation('r1'),
            self::text('r2', '答え'),
            self::end('r2'),
        ], false);

        $this->assertSame(['user_text', 'cancellation', 'model_text', 'response_end'], self::types($repaired));
    }

    /** 結果の無い呼び出しのブロックを外し、ブロックが残らない文とその応答の終わりを外す */
    public function test_calls_without_results_are_dropped(): void
    {
        $repaired = HistoryRepair::repaired([
            ConversationItem::userText('問い'),
            self::calls('r1', 'c1', 'c2'),
            self::end('r1'),
            ConversationItem::toolResult('c1', null, [], '一', false),
            self::calls('r2', 'c3'),
            self::end('r2'),
        ], false);

        $this->assertSame(['user_text', 'model_text', 'response_end', 'tool_result'], self::types($repaired));
        $blocks = $repaired[1] instanceof ConversationItem ? $repaired[1]->content['blocks'] : [];
        $this->assertSame(['c1'], array_column($blocks, 'id'));
    }

    /** 同じ項目の文のブロックは残す */
    public function test_the_text_beside_an_unresolved_call_is_kept(): void
    {
        $mixed = ConversationItem::modelText('r1', [
            ConversationItem::textBlock('見ます'),
            ConversationItem::toolCallBlock('c1', 'search', []),
        ]);

        $repaired = HistoryRepair::repaired([ConversationItem::userText('問い'), $mixed, self::end('r1')], false);

        $kept = $repaired[1] instanceof ConversationItem ? $repaired[1]->content['blocks'] : [];
        $this->assertSame([['type' => 'text', 'text' => '見ます']], $kept);
        $this->assertSame(['user_text', 'model_text', 'response_end'], self::types($repaired));
    }

    /** 空の応答の id の取り消しの印でも、応答に属さない項目は残す */
    public function test_items_outside_responses_survive_any_cancellation(): void
    {
        $items = [ConversationItem::userText('問い'), ConversationItem::cancellation('')];

        $this->assertSame(['user_text', 'cancellation'], self::types(HistoryRepair::repaired($items, false)));
    }

    public function test_awaiting_calls_are_kept(): void
    {
        $items = [ConversationItem::userText('問い'), self::calls('r1', 'c1'), self::end('r1')];

        $repaired = HistoryRepair::repaired($items, true, ['c1']);

        $this->assertSame(['user_text', 'model_text', 'response_end'], self::types($repaired));
    }

    public function test_a_blank_model_text_is_dropped(): void
    {
        $items = [ConversationItem::userText('問い'), self::text('r1', " \n"), self::end('r1')];

        $repaired = HistoryRepair::repaired($items, false);

        $this->assertSame(['user_text'], self::types($repaired));
    }

    /**
     * 前の実行が worker が落ちて終わったときだけ末尾を見る(末尾の判定は利用者の文・モデルの文・道具の結果だけ)。
     *
     * @return array<string, array{list<ConversationItem>, bool, list<string>}>
     */
    public static function tails(): array
    {
        $result = [self::calls('r1', 'c1'), self::end('r1'), ConversationItem::toolResult('c1', null, [], '一', false)];

        return [
            '道具の結果で切れた' => [$result, true, ['model_text', 'response_end', 'tool_result', 'resume_after_cut']],
            '利用者の文で切れた(後ろの印は飛ばす)' => [
                [ConversationItem::userText('問い'), ConversationItem::interruption(false)],
                true,
                ['user_text', 'no_response', 'interruption'],
            ],
            'モデルの文で終わった' => [
                [ConversationItem::userText('問い'), self::text('r1', '答え')],
                true,
                ['user_text', 'model_text'],
            ],
            'ほかの終わり方では見ない' => [$result, false, ['model_text', 'response_end', 'tool_result']],
        ];
    }

    /**
     * @param  list<ConversationItem>  $items
     * @param  list<string>  $expected
     */
    #[DataProvider('tails')]
    public function test_the_tail_after_a_lost_worker(array $items, bool $workerLost, array $expected): void
    {
        $this->assertSame($expected, self::types(HistoryRepair::repaired($items, $workerLost)));
    }

    private static function text(string $responseId, string $text): ConversationItem
    {
        return ConversationItem::modelText($responseId, [ConversationItem::textBlock($text)]);
    }

    private static function calls(string $responseId, string ...$ids): ConversationItem
    {
        $blocks = array_values(array_map(
            static fn (string $id): array => ConversationItem::toolCallBlock($id, 'search', []),
            $ids,
        ));

        return ConversationItem::modelText($responseId, $blocks);
    }

    private static function end(string $responseId): ConversationItem
    {
        $usage = [
            'input_tokens' => 1,
            'output_tokens' => 1,
            'cache_read_input_tokens' => null,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => null,
        ];

        $at = new DateTimeImmutable('2026-11-19');

        return ConversationItem::responseEnd($responseId, $usage, null, 'anthropic', 'm', 1, [], $at);
    }

    /**
     * @param  list<ConversationItem|TransientText>  $items
     * @return list<string>
     */
    private static function types(array $items): array
    {
        return array_map(static fn (ConversationItem|TransientText $item): string => $item instanceof TransientText
            ? match ($item->template) {
                AiInstructionTemplate::ResumeAfterCut => 'resume_after_cut',
                AiInstructionTemplate::NoResponse => 'no_response',
                default => $item->template->value,
            }
            : $item->type->value, $items);
    }
}
