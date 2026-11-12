<?php

namespace Tests\Feature\Ai;

use App\Queries\Ai\Api\RecordMarkers;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * 結果のレコードの印の部品(docs の 設計/AI/道具と権限/README.md 10.2 の「結果のレコードの印と根拠」・まとめた実行と送るデータ.md 10.6 の (1)):
 * 付けた印を取り出せ、最初に出た順で同じ印は 1 つ、形の合わない物を拾わない。外から来る値の中の印の形は、印と読めない形に直る。
 */
final class RecordMarkersTest extends TestCase
{
    public function test_markers_round_trip_in_the_order_they_first_appear_without_repeats(): void
    {
        $text = 'see '.RecordMarkers::of('meetings', 12).' and '.RecordMarkers::of('deal_2', 3)
            .' again '.RecordMarkers::of('meetings', 12);

        $this->assertSame('[[rec:meetings:12]]', RecordMarkers::of('meetings', 12));
        $expected = [['module' => 'meetings', 'id' => 12], ['module' => 'deal_2', 'id' => 3]];
        $this->assertSame($expected, RecordMarkers::extract($text));
    }

    #[TestWith(['[[rec:Meetings:1]]'])]
    #[TestWith(['[[rec:meetings:0]]'])]
    #[TestWith(['[[rec:meetings:012]]'])]
    #[TestWith(['[[rec:meetings:1]'])]
    #[TestWith(['[[rec:1meetings:1]]'])]
    #[TestWith(['[[rec:meetings:]]'])]
    public function test_a_malformed_marker_is_not_picked_up(string $text): void
    {
        $this->assertSame([], RecordMarkers::extract($text));
    }

    #[TestWith(['Meetings', 1])]
    #[TestWith(['meetings', 0])]
    #[TestWith(['', 1])]
    public function test_a_marker_out_of_the_form_is_a_logic_error(string $module, int $id): void
    {
        $this->expectException(LogicException::class);

        RecordMarkers::of($module, $id);
    }

    /** 値の中の印の形は拾われない形に直り、印の形を含まない値は変わらない */
    public function test_a_value_with_the_marker_form_is_neutralized(): void
    {
        $value = '前 [[rec:meetings:9]] 後 [[rec:';

        $this->assertSame('前 [ [rec:meetings:9]] 後 [ [rec:', RecordMarkers::neutralize($value));
        $this->assertSame([], RecordMarkers::extract(RecordMarkers::neutralize($value)));
        $this->assertSame('ふつうの [rec] 値', RecordMarkers::neutralize('ふつうの [rec] 値'));
    }
}
