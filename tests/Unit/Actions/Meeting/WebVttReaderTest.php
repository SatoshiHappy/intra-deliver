<?php

namespace Tests\Unit\Actions\Meeting;

use App\Actions\Meeting\TranscriptLine;
use App\Actions\Meeting\WebVttReader;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 字幕のファイル(.vtt)の行(docs の 設計/業務ツール/会議/README.md の 2 節): キュー 1 つを 1 行にし、<v 名前> を話者のラベルに、時刻を秒にする。
 * 壊れたキューがあればファイル全体を断る。
 */
final class WebVttReaderTest extends TestCase
{
    public function test_reads_cues_with_speakers_times_settings_tags_and_character_references(): void
    {
        $content = implode("\n", [
            'WEBVTT - 定例の字幕',
            'Kind: captions',
            '',
            'NOTE これは注で、',
            '読まない',
            '',
            'STYLE',
            '::cue { color: red }',
            '',
            'cue-1',
            '00:00:01.000 --> 00:00:04.500 align:start position:10%',
            '<v.loud 佐藤 花子>はじめます</v>',
            '<b>本日は</b> &amp; よろしく',
            '',
            '00:59.250 --> 01:00.000',
            '<v >名前の無い話者&lt;です&gt;',
            '',
            '01:02:03.004 --> 01:02:03.004',
            '<v.loud>クラスだけ</v>',
            '',
            '00:01:00.000 --> 00:01:01.000',
            '<v 鈴木>  ',
            '',
            "10:00:00.000 --> 10:00:01.000\t",
            '<v '.str_repeat('長', 101).'>ラベルが長い',
        ]);

        $this->assertEquals([
            new TranscriptLine('はじめます 本日は & よろしく', '佐藤 花子', '1.000', '4.500'),
            new TranscriptLine('名前の無い話者<です>', null, '59.250', '60.000'),
            new TranscriptLine('クラスだけ', null, '3723.004', '3723.004'),
            new TranscriptLine('ラベルが長い', str_repeat('長', 100), '36000.000', '36001.000'),
        ], (new WebVttReader)->lines($content, 'file'));
    }

    public function test_the_header_may_be_followed_by_a_tab_and_text(): void
    {
        $lines = (new WebVttReader)->lines("WEBVTT\tヘッダー\n\n00:01.000 --> 00:02.000\n発言", 'file');

        $this->assertEquals([new TranscriptLine('発言', null, '1.000', '2.000')], $lines);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedFiles(): array
    {
        $cue = "\n\n00:01.000 --> 00:02.000\n発言";

        return [
            '先頭行が WEBVTT でない' => ["WEBVTTX{$cue}"],
            '先頭行が無い' => ["00:01.000 --> 00:02.000\n発言"],
            '空のファイル' => [''],
            '時刻の行が読めない' => ["WEBVTT{$cue}\n\n00:03.000 -> 00:04.000\n壊れたキュー"],
            '時刻の行が無い' => ["WEBVTT{$cue}\n\nid\n本文だけ"],
            '分が 60' => ["WEBVTT\n\n00:60:00.000 --> 01:00:00.000\n発言"],
            'ミリ秒が 3 桁でない' => ["WEBVTT\n\n00:01.00 --> 00:02.000\n発言"],
            '終わりが始まりより前' => ["WEBVTT\n\n00:02.000 --> 00:01.999\n発言"],
            '秒の上限を超える' => ["WEBVTT\n\n2777:46:39.000 --> 2777:46:40.000\n発言"],
            '時の桁が多すぎる' => ["WEBVTT\n\n00:01.000 --> 99999999999999999999:00:00.000\n発言"],
        ];
    }

    #[DataProvider('malformedFiles')]
    public function test_a_broken_file_or_cue_rejects_the_whole_file(string $content): void
    {
        try {
            (new WebVttReader)->lines($content, 'file');
            $this->fail('断られなかった');
        } catch (ValidationException $exception) {
            $this->assertSame(['file' => [WebVttReader::MALFORMED]], $exception->errors());
        }
    }

    public function test_the_upper_limit_of_seconds_is_accepted(): void
    {
        $lines = (new WebVttReader)->lines("WEBVTT\n\n2777:46:39.999 --> 2777:46:39.999\n発言", 'file');

        $this->assertEquals([new TranscriptLine('発言', null, '9999999.999', '9999999.999')], $lines);
    }

    public function test_empty_cues_are_skipped_and_a_file_of_only_empty_cues_has_no_lines(): void
    {
        $this->assertSame([], (new WebVttReader)->lines("WEBVTT\n\n00:01.000 --> 00:02.000\n<v 佐藤></v>", 'file'));
    }
}
