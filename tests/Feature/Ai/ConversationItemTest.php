<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationItem;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemDisplay;
use App\Enums\Ai\ConversationItemType;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 会話の保存の項目の形(docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「項目の種類」): 種類ごとに作り手から写しと読みを往復して同じ値になり、
 * 種類と出し方と形の版を持つ(形に合わない中身 = ConversationItemShapeTest)。
 */
final class ConversationItemTest extends TestCase
{
    use ConversationItemSamples;

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
            ConversationItem::toolCallBlock('call_2', 'list_modules', [], 'fc_2'),
        ];

        return [
            '利用者の文' => [ConversationItem::userText('会議を探して'), 'user_text', 'show'],
            'モデルの文' => [ConversationItem::modelText('r1', $blocks), 'model_text', 'show'],
            '応答の終わり' => [self::responseEnd(), 'response_end', 'hide'],
            '応答の終わり(生の状態が無い)' => [
                ConversationItem::responseEnd('r2', self::USAGE, null, 'openai', 'gpt-x', 0, [], new DateTimeImmutable),
                'response_end',
                'hide',
            ],
            '取り消しの印' => [ConversationItem::cancellation('r1'), 'cancellation', 'hide'],
            ...self::resultItems(),
            ...self::markerItems(),
        ];
    }

    /**
     * 道具の結果と、その種類と出し方の値。
     *
     * @return array<string, array{ConversationItem, string, string}>
     */
    private static function resultItems(): array
    {
        return [
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
                ConversationItem::toolResult(
                    'c',
                    AiInstructionTemplate::EmptyResult,
                    ['tool_name' => 't'],
                    null,
                    false,
                ),
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
        ];
    }

    /**
     * 印・足す文・要約の項目と、その種類と出し方の値。
     *
     * @return array<string, array{ConversationItem, string, string}>
     */
    private static function markerItems(): array
    {
        return [
            '中断の印(道具の途中)' => [ConversationItem::interruption(true), 'interruption', 'show'],
            '中断の印(道具の途中でない)' => [ConversationItem::interruption(false), 'interruption', 'show'],
            '回数の上限の印' => [ConversationItem::turnLimit(), 'turn_limit', 'show'],
            '足す文(結果の分からない書き込みの知らせ)' => [
                ConversationItem::addedText(AiInstructionTemplate::UnknownOutcomeNotice, [
                    'unknown_calls' => ['会議の登録'],
                ], ['call_1']),
                'added_text',
                'hide',
            ],
            '足す文(日付の変化)' => [
                ConversationItem::addedText(AiInstructionTemplate::DateChange, ['new_date' => '2026-11-18'], []),
                'added_text',
                'hide',
            ],
            '要約の境目' => [ConversationItem::compactBoundary(5000, '2026-11-17'), 'compact_boundary', 'boundary_line'],
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
        $item = ConversationItem::modelText('r1', [
            array_reverse(ConversationItem::textBlock('探します'), true),
            array_reverse(ConversationItem::toolCallBlock('call_1', 'search_records', $arguments), true),
        ]);

        $this->assertSame($item->toArray(), ConversationItem::fromArray($item->toArray())->toArray());
        $this->assertSame($arguments, $item->toArray()['content']['blocks'][1]['arguments']);
    }

    /** 応答の終わりの時刻は、渡した時刻の時差のまま秒まで持つ */
    public function test_a_response_end_keeps_the_finished_time_to_the_second(): void
    {
        $this->assertSame('2026-11-17T10:00:00+09:00', self::responseEnd()->content['finished_at']);
    }
}
