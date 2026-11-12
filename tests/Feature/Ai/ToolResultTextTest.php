<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\AgentToolResult;
use App\Actions\Ai\Api\RecordMarker;
use App\Actions\Ai\ToolCalls\ToolResultText;
use App\Enums\Ai\AiInstructionTemplate;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Tests\Feature\Ai\Tools\FakeTools;
use Tests\Feature\Ai\Tools\Generic\GenericTool;

/**
 * 道具の結果の本文と長さ(docs の 設計/AI/道具と権限/まとめた実行と送るデータ.md 10.7 のテストの項のうち、書く道具・次の回・送る文を除く): 上限ちょうど・
 * 1 少ないは切らず、1 多いは先頭と大きい結果の定型と値。既定より大きい上限は既定で切る。先頭の中の最後の改行が決まった位置の前・ちょうど・後
 * (後ろのときだけ改行で切る)。長さはコードポイントの数。空の結果は空の結果の定型。値の中の印の形は直り、印の場所は印になる。
 */
final class ToolResultTextTest extends TestCase
{
    /** 全文の長さ(結果の値 ['v' => 文字列] の JSON の囲み = 8 字) */
    private const WRAP = 8;

    #[TestWith([2999, false])]
    #[TestWith([3000, false])]
    #[TestWith([3001, true])]
    public function test_the_limit_of_the_tool_cuts_only_above_it(int $length, bool $cut): void
    {
        $tool = new GenericTool('ai_read', maxLength: 3000);

        $shaped = ToolResultText::shaped($tool, self::resultOf($length), 'ai_read');

        $this->assertSame($length, mb_strlen($shaped['full']));
        $this->assertSame($cut ? AiInstructionTemplate::LargeResultRead : null, $shaped['template']);
        $this->assertSame($cut ? mb_substr($shaped['full'], 0, 2000) : $shaped['full'], $shaped['body']);
        $values = $cut ? ['original_length' => (string) $length, 'preview_length' => '2000'] : [];
        $this->assertSame($values, $shaped['values']);
    }

    /** 上限は道具の値か既定の小さい方(既定より大きい上限は既定で切る。答えない道具は既定) */
    #[TestWith([null])]
    #[TestWith([90_000])]
    public function test_the_default_limit_caps_the_limit_of_the_tool(?int $limit): void
    {
        $truncated = AiInstructionTemplate::LargeResultTruncated;
        $tool = new GenericTool('ai_read', maxLength: $limit, largeTemplate: $truncated);

        $within = ToolResultText::shaped($tool, self::resultOf(ToolResultText::DEFAULT_LIMIT), 'ai_read');
        $above = ToolResultText::shaped($tool, self::resultOf(ToolResultText::DEFAULT_LIMIT + 1), 'ai_read');

        $this->assertNull($within['template']);
        $this->assertSame(AiInstructionTemplate::LargeResultTruncated, $above['template']);
        $this->assertSame((string) ToolResultText::PREVIEW_LENGTH, $above['values']['preview_length']);
    }

    /** 先頭の中の最後の改行が決まった位置(1,000 字目)の前・ちょうどなら先頭のまま、後ろならその改行で切る */
    #[TestWith([999, 2000])]
    #[TestWith([1000, 2000])]
    #[TestWith([1001, 1001])]
    public function test_the_preview_breaks_at_the_last_newline_only_after_the_position(int $newline, int $length): void
    {
        $text = str_repeat('あ', $newline)."\n".str_repeat('い', 3000);

        $preview = ToolResultText::preview($text, 100);

        $this->assertSame($length, mb_strlen((string) $preview));
        $this->assertSame(mb_substr($text, 0, $length), $preview);
    }

    /** 長さはコードポイントの数(サロゲートの対になる字は 1、結合文字は 2。上限は起動の確かめが許すいちばん小さい値) */
    public function test_the_length_counts_code_points(): void
    {
        $limit = ToolResultText::PREVIEW_LENGTH;
        $tool = new GenericTool('ai_read', maxLength: $limit);
        $emoji = "\u{1F600}";
        $value = str_repeat($emoji, $limit - self::WRAP - 2)."e\u{0301}";

        $fits = ToolResultText::shaped($tool, new AgentToolResult(['v' => $value]), 'ai_read');
        $over = ToolResultText::shaped($tool, new AgentToolResult(['v' => $value.$emoji]), 'ai_read');

        $this->assertNull($fits['template']);
        $this->assertSame((string) ($limit + 1), $over['values']['original_length']);
    }

    public function test_an_empty_result_is_the_empty_result_template_without_a_body(): void
    {
        $shaped = ToolResultText::shaped(FakeTools::read(), new AgentToolResult([]), 'ai_read');

        $this->assertSame(AiInstructionTemplate::EmptyResult, $shaped['template']);
        $this->assertSame([['tool_name' => 'ai_read'], null], [$shaped['values'], $shaped['body']]);
    }

    /** 値と鍵の中の印の形は直り、印の場所は印になる(JSON は整形せず、Unicode と / をそのまま) */
    public function test_values_are_neutralized_and_marker_places_become_markers(): void
    {
        $result = new AgentToolResult([
            'record' => new RecordMarker('meetings', 5),
            'title' => '会議 [[rec:meetings:6]] a/b',
            '[[rec:x:1]]' => ['list' => ['[[rec:x:2]]']],
        ]);

        $full = ToolResultText::full($result);

        $expected = '{"record":"[[rec:meetings:5]]","title":"会議 [ [rec:meetings:6]] a/b",'
            .'"[ [rec:x:1]]":{"list":["[ [rec:x:2]]"]}}';
        $this->assertSame($expected, $full);
    }

    /** 全文の長さが $length の結果 */
    private static function resultOf(int $length): AgentToolResult
    {
        return new AgentToolResult(['v' => str_repeat('x', $length - self::WRAP)]);
    }
}
