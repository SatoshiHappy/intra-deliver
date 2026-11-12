<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationItem;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemDisplay;
use App\Enums\Ai\ConversationItemType;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 会話の保存の項目の形(docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「項目の種類」): 種類ごとに作り手から写しと読みを往復して同じ値になり、
 * 種類と出し方と形の版を持つ。知らない形の版・種類・出し方と、種類の形に合わない中身の写しを読むと作りの誤り。
 */
final class ConversationItemTest extends TestCase
{
    /**
     * 項目と、その種類と出し方の値。
     *
     * @return array<string, array{ConversationItem, string, string}>
     */
    public static function items(): array
    {
        $blocks = [
            ConversationItem::textBlock('探します'),
            ConversationItem::toolCallBlock('call_1', 'search_records', ['module' => 'meetings', 'limit' => 5]),
        ];

        return [
            '利用者の文' => [ConversationItem::userText('会議を探して'), 'user_text', 'show'],
            'モデルの文' => [ConversationItem::modelText($blocks), 'model_text', 'show'],
            '道具の結果(本文だけ)' => [ConversationItem::toolResult('call_1', null, [], '3 件', false), 'tool_result', 'hide'],
            '道具の失敗の結果(定型と値)' => [
                ConversationItem::toolResult('call_1', AiInstructionTemplate::ToolInputInvalid, [
                    'tool_name' => 'search_records',
                    'problems' => ['module: 欠けている', 'page: 型が違う(integer)'],
                ], null, true),
                'tool_result',
                'hide',
            ],
            '大きい結果(定型と値と本文)' => [
                ConversationItem::toolResult('call_1', AiInstructionTemplate::LargeResultRead, [
                    'original_length' => '60000',
                    'preview_length' => '2000',
                ], '先頭', false),
                'tool_result',
                'hide',
            ],
            '空の結果(本文が無い)' => [
                ConversationItem::toolResult('c', AiInstructionTemplate::EmptyResult, ['k' => 't'], null, false),
                'tool_result',
                'hide',
            ],
            '理由つきの断り' => [
                ConversationItem::toolResult('call_1', AiInstructionTemplate::ToolUseRejectedWithReason, [
                    ConversationItem::USER_FEEDBACK => '先に別の件を',
                ], null, true),
                'tool_result',
                'reason_as_user_text',
            ],
            '中断の印(道具の途中)' => [ConversationItem::interruption(true), 'interruption', 'show'],
            '中断の印(道具の途中でない)' => [ConversationItem::interruption(false), 'interruption', 'show'],
            '回数の上限の印' => [ConversationItem::turnLimit(), 'turn_limit', 'show'],
            '要約の境目' => [ConversationItem::compactBoundary(), 'compact_boundary', 'boundary_line'],
            '要約の文' => [ConversationItem::compactSummary('ここまでの要約'), 'compact_summary', 'hide'],
        ];
    }

    #[DataProvider('items')]
    public function test_an_item_round_trips_through_its_copy(
        ConversationItem $item,
        string $type,
        string $display,
    ): void {
        $copy = json_decode((string) json_encode($item->toArray()), true);

        $this->assertIsArray($copy);
        $this->assertEquals($item, ConversationItem::fromArray($copy));
        $expected = [ConversationItemType::from($type), ConversationItemDisplay::from($display), 2];
        $this->assertSame($expected, [$item->type, $item->display, $item->version]);
        $this->assertSame(['type' => $type, 'display' => $display, 'version' => 2], array_slice($copy, 0, 3));
    }

    /** 写しを持つ側が中身の鍵の順を変えても(jsonb は鍵を並べ替える)、読むと同じ値になる */
    public function test_a_copy_with_its_keys_reordered_reads_as_the_same_item(): void
    {
        $values = ['tool_name' => 'a', 'denial_detail' => 'b'];
        $item = ConversationItem::toolResult('call_1', AiInstructionTemplate::RuleDenied, $values, null, true);
        $copy = $item->toArray();
        $copy['content'] = array_reverse($copy['content'], true);

        $this->assertSame($item->content, ConversationItem::fromArray(array_reverse($copy, true))->content);
    }

    /**
     * モデルの文は渡されたブロックを作り手で作り直して持つ: 鍵の順を逆にしたブロックで作った項目の写しと、その写しから読み戻した項目の写しが
     * 同じになる(道具の呼び出しの引数は渡されたまま)。
     */
    public function test_a_model_text_made_from_blocks_with_reordered_keys_has_the_same_copy_as_its_read_back(): void
    {
        $arguments = ['zeta' => 1, 'alpha' => ['beta' => 'b']];
        $item = ConversationItem::modelText([
            array_reverse(ConversationItem::textBlock('探します'), true),
            array_reverse(ConversationItem::toolCallBlock('call_1', 'search_records', $arguments), true),
        ]);

        $this->assertSame($item->toArray(), ConversationItem::fromArray($item->toArray())->toArray());
        $this->assertSame($arguments, $item->toArray()['content']['blocks'][1]['arguments']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unreadableCopies(): array
    {
        $copy = ConversationItem::userText('文')->toArray();
        $result = ConversationItem::toolResult('call_1', null, [], '結果', false)->toArray();

        return [
            '形の版 1 の道具の結果(前の形の固定の写し)' => [[
                'type' => 'tool_result',
                'display' => 'hide',
                'version' => 1,
                'content' => ['call_id' => 'call_1', 'text' => '結果', 'is_error' => false, 'reason' => null],
            ]],
            '形の版 1 の利用者の文' => [[...$copy, 'version' => 1]],
            '知らない形の版' => [[...$copy, 'version' => 3]],
            '形の版が無い' => [array_diff_key($copy, ['version' => true])],
            '知らない種類' => [[...$copy, 'type' => 'image']],
            '知らない出し方' => [[...$copy, 'display' => 'blink']],
            '中身が種類の形に合わない' => [[...$copy, 'content' => ['text' => 1]]],
            '中身に余分な鍵' => [[...$copy, 'content' => ['text' => '文', 'extra' => true]]],
            'モデルの文の知らないブロック' => [[
                ...ConversationItem::modelText([])->toArray(),
                'content' => ['blocks' => [['type' => 'x']]],
            ]],
            '出し方が中身と合わない' => [[...$result, 'display' => 'show']],
            '知らない定型の種類' => [[...$result, 'content' => [...$result['content'], 'template' => 'nope']]],
            '定型の値が文字列でない' => [[...$result, 'content' => [...$result['content'], 'values' => ['a' => 1]]]],
            '定型の値の列に文字列でない物' => [[...$result, 'content' => [...$result['content'], 'values' => ['a' => ['x', 2]]]]],
            '定型の値の鍵が文字列でない' => [[...$result, 'content' => [...$result['content'], 'values' => ['x']]]],
            '境目に中身' => [[...ConversationItem::compactBoundary()->toArray(), 'content' => ['text' => '文']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $copy
     */
    #[DataProvider('unreadableCopies')]
    public function test_a_copy_that_does_not_fit_is_a_logic_error(array $copy): void
    {
        $this->expectException(LogicException::class);

        ConversationItem::fromArray($copy);
    }
}
