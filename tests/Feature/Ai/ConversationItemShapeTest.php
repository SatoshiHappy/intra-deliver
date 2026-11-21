<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Api\ConversationItemShape;
use App\Enums\Ai\AiInstructionTemplate;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 会話の保存の項目の形の確かめ(docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「項目の種類」): 知らない形の版・種類・出し方と、種類の形と
 * 定型の種類ごとの欄の規則に合わない中身は、写しを読むときも作り手で作るときも作りの誤り。
 */
final class ConversationItemShapeTest extends TestCase
{
    use ConversationItemSamples;

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
                ...ConversationItem::modelText('r1', [])->toArray(),
                'content' => ['response_id' => 'r1', 'blocks' => [['type' => 'x']]],
            ]],
            'モデルの文に応答の id が無い' => [
                [...ConversationItem::modelText('r1', [])->toArray(), 'content' => ['blocks' => []]],
            ],
            '道具の呼び出しに結果を結ぶ id の鍵が無い' => [[
                ...ConversationItem::modelText('r1', [])->toArray(),
                'content' => [
                    'response_id' => 'r1',
                    'blocks' => [['type' => 'tool_call', 'id' => 'c', 'name' => 'n', 'arguments' => []]],
                ],
            ]],
            ...self::unreadableKindCopies(),
            '出し方が中身と合わない' => [[...$result, 'display' => 'show']],
            '知らない定型の種類' => [[...$result, 'content' => [...$result['content'], 'template' => 'nope']]],
            '定型の値が文字列でない' => [[...$result, 'content' => [...$result['content'], 'values' => ['a' => 1]]]],
            '定型の値の列に文字列でない物' => [[...$result, 'content' => [...$result['content'], 'values' => ['a' => ['x', 2]]]]],
            '定型の値の鍵が文字列でない' => [[...$result, 'content' => [...$result['content'], 'values' => ['x']]]],
            '回数の上限の印に中身' => [[...ConversationItem::turnLimit()->toArray(), 'content' => ['text' => '文']]],
        ];
    }

    /**
     * 応答の終わり・足す文・要約の境目の、形に合わない写し。
     *
     * @return array<string, array{array<string, mixed>}>
     */
    private static function unreadableKindCopies(): array
    {
        $boundary = ConversationItem::compactBoundary(1, '2026-11-17', 1);

        return [
            '応答の終わりの使用量に鍵が欠ける' => [self::withContent(self::responseEnd(), ['usage' => ['input_tokens' => 1]])],
            '応答の終わりの使用量が数でない' => [
                self::withContent(self::responseEnd(), ['usage' => [...self::USAGE, 'output_tokens' => '1']]),
            ],
            '応答の終わりの呼び出しの id の列に数' => [self::withContent(self::responseEnd(), ['call_ids' => ['a', 1]])],
            '応答の終わりの時刻の形が違う' => [self::withContent(self::responseEnd(), ['finished_at' => '2026-11-17 10:00'])],
            '足す文に定型の種類が無い' => [self::withContent(self::dateChange(), ['template' => null])],
            '境目の日の形が違う' => [self::withContent($boundary, ['date' => '2026/11/17'])],
            '境目の見積もりが数でない' => [self::withContent($boundary, ['estimate' => '1'])],
            '境目の見積もりが負' => [self::withContent($boundary, ['estimate' => -1])],
            '応答の終わりのブロックの数が負' => [self::withContent(self::responseEnd(), ['block_count' => -1])],
            ...self::unreadableTemplateCopies(),
        ];
    }

    /**
     * 定型の種類ごとの欄の規則(足す文・道具の結果)に合わない写し。
     *
     * @return array<string, array{array<string, mixed>}>
     */
    private static function unreadableTemplateCopies(): array
    {
        $result = ConversationItem::toolResult('call_1', null, [], '結果', false);

        return [
            '足す文に道具の結果の定型' => [self::withContent(self::dateChange(), ['template' => 'tool_use_rejected'])],
            '日付の変化の日の形が違う' => [self::withContent(self::dateChange(), ['values' => ['new_date' => '11/18']])],
            '日付の変化の値の鍵が違う' => [self::withContent(self::dateChange(), ['values' => ['date' => '2026-11-18']])],
            '日付の変化が呼び出しの id を持つ' => [self::withContent(self::dateChange(), ['call_ids' => ['call_1']])],
            '書き込みの知らせの値が空' => [self::withContent(self::unknownWrites(), ['values' => ['unknown_calls' => []]])],
            '書き込みの知らせが呼び出しの id を持たない' => [self::withContent(self::unknownWrites(), ['call_ids' => []])],
            '定型の無い道具の結果が値を持つ' => [self::withContent($result, ['values' => ['tool_name' => 'x']])],
            '道具の結果に足す文の定型' => [
                self::withContent($result, ['template' => 'date_change', 'values' => ['new_date' => '2026-11-18']]),
            ],
            '道具の結果に送るときだけの定型' => [self::withContent($result, ['template' => 'tool_result_missing'])],
            '道具の結果の値の鍵が欠ける' => [
                self::withContent($result, ['template' => 'rule_denied', 'values' => ['tool_name' => 'x']]),
            ],
            '大きい結果が本文の口を値に持つ' => [self::withContent($result, ['template' => 'large_result_read', 'values' => [
                'original_length' => '1',
                'preview_length' => '1',
                'preview' => '先頭',
            ]])],
            ...self::unreadableBodyCopies(),
        ];
    }

    /**
     * 定型の種類と項目の種類・本文の有無・値の要素の空の組のうち、形に合わない写し。
     *
     * @return array<string, array{array<string, mixed>}>
     */
    private static function unreadableBodyCopies(): array
    {
        $result = ConversationItem::toolResult('call_1', null, [], '結果', false);
        $large = ['template' => 'large_result_read', 'values' => ['original_length' => '9', 'preview_length' => '1']];

        return [
            '道具の結果に中断の印の定型' => [self::withContent($result, ['template' => 'interrupted'])],
            '大きい結果に本文が無い' => [self::withContent($result, [...$large, 'body' => null])],
            '本文の口を持たない定型に本文がある' => [self::withContent($result, ['template' => 'tool_use_rejected'])],
            '定型も本文も無い道具の結果' => [self::withContent($result, ['body' => null])],
            '並びの鍵に文字列' => [self::withContent($result, [
                'template' => 'tool_input_invalid',
                'values' => ['tool_name' => 'x', 'problems' => '1 つ'],
                'body' => null,
            ])],
            '文字列の鍵に並び' => [self::withContent($result, [
                'template' => 'tool_input_invalid',
                'values' => ['tool_name' => ['x'], 'problems' => ['1 つ']],
                'body' => null,
            ])],
            '書き込みの知らせの並びの鍵に文字列' => [
                self::withContent(self::unknownWrites(), ['values' => ['unknown_calls' => '会議の登録']]),
            ],
            '書き込みの知らせが空の文字列 1 つの列' => [
                self::withContent(self::unknownWrites(), ['values' => ['unknown_calls' => ['']]]),
            ],
            '書き込みの知らせが空の文字列を含む列' => [
                self::withContent(self::unknownWrites(), ['values' => ['unknown_calls' => ['会議の登録', '']]]),
            ],
        ];
    }

    private static function dateChange(): ConversationItem
    {
        return ConversationItem::addedText(AiInstructionTemplate::DateChange, ['new_date' => '2026-11-18'], []);
    }

    private static function unknownWrites(): ConversationItem
    {
        $values = ['unknown_calls' => ['会議の登録']];

        return ConversationItem::addedText(AiInstructionTemplate::UnknownOutcomeNotice, $values, ['call_1']);
    }

    public function test_a_factory_refuses_content_out_of_the_form(): void
    {
        $this->expectException(LogicException::class);

        ConversationItem::addedText(AiInstructionTemplate::DateChange, ['new_date' => '明日'], []);
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

    /**
     * 項目の写しの中身の一部を差し替えた写し。
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private static function withContent(ConversationItem $item, array $content): array
    {
        $copy = $item->toArray();

        return [...$copy, 'content' => [...$copy['content'], ...$content]];
    }

    public function test_a_compact_boundary_with_a_date_out_of_its_form_is_a_logic_error(): void
    {
        $this->expectException(LogicException::class);

        ConversationItem::compactBoundary(1, '2026-11-17T00:00:00', 1);
    }

    /** 使用量の並べ直しの鍵の順は、形の確かめの使用量の鍵の順と同じ(順の正本 = ConversationItemShape::USAGE) */
    public function test_ordered_usage_follows_the_key_order_of_the_shape(): void
    {
        $reversed = array_reverse(self::USAGE, true);

        $ordered = ConversationItemShape::orderedUsage($reversed);

        $this->assertSame(array_keys(ConversationItemShape::USAGE), array_keys($ordered));
    }
}
