<?php

namespace Tests\Feature\Meeting;

use App\Actions\Meeting\CreateMeeting;
use App\Actions\Meeting\TextImportReader;
use App\Actions\Meeting\WebVttReader;
use App\Models\Meeting\Transcript;
use App\Models\Meeting\TranscriptSegment;
use App\Queries\Meeting\MeetingLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 作成の入力のテキストの確かめ(docs の 設計/業務ツール/会議/処理.md の 3.2 の 1、README.md の 2 節の文字コード): 422 のキーは出どころの入力の名前で、
 * 断ったときは何も残らない。BOM は外し、行の数の上限ちょうどは通る。.vtt の行の細部は tests/Unit/Actions/Meeting/WebVttReaderTest。
 */
final class MeetingTextImportTest extends TransactionalTestCase
{
    use UsesMeetings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function rejectedInputs(): array
    {
        $tooLarge = str_repeat('a', MeetingLimits::TEXT_MAX_BYTES + 1);

        return [
            '両方' => [['file' => ['a.txt', '発言'], 'text' => '発言'], 'file', CreateMeeting::FILE_OR_TEXT],
            'どちらも無い' => [[], 'file', CreateMeeting::FILE_OR_TEXT],
            '形式' => [['file' => ['会議.mp3', '発言']], 'file', TextImportReader::UNSUPPORTED],
            '拡張子が無い' => [['file' => ['会議', '発言']], 'file', TextImportReader::UNSUPPORTED],
            'ファイルが 1MB を超える' => [['file' => ['a.txt', $tooLarge]], 'file', TextImportReader::FILE_TOO_LARGE],
            '貼り付けが 1MB を超える' => [['text' => $tooLarge], 'text', TextImportReader::PASTE_TOO_LARGE],
            'UTF-8 でない' => [['file' => ['a.txt', "\x82\xA0\x82\xA2"]], 'file', TextImportReader::NOT_UTF8],
            'ファイルに U+0000' => [['file' => ['a.txt', "発\0言"]], 'file', TextImportReader::NOT_UTF8],
            '貼り付けに U+0000' => [['text' => "発\0言"], 'text', TextImportReader::PASTE_INVALID_CHARACTER],
            '空' => [['file' => ['a.txt', " \n\u{3000}\n"]], 'file', TextImportReader::EMPTY],
            '.vtt の先頭行' => [
                ['file' => ['a.vtt', "WEBVTTX\n\n00:01.000 --> 00:02.000\n発言"]], 'file', WebVttReader::MALFORMED,
            ],
            'ファイルが 10,001 行' => [
                ['file' => ['a.txt', str_repeat("行\n", 10_001)]], 'file', TextImportReader::TOO_MANY_LINES,
            ],
            '貼り付けが 10,001 行' => [['text' => str_repeat("行\n", 10_001)], 'text', TextImportReader::TOO_MANY_LINES],
        ];
    }

    /**
     * @param  array<string, mixed>  $input  file = [名前, 中身]
     */
    #[DataProvider('rejectedInputs')]
    public function test_rejects_a_text_that_cannot_be_imported(array $input, string $key, string $message): void
    {
        $counts = $this->counts();

        $this->postMeeting($input)->assertStatus(302)->assertSessionHasErrors([$key => $message]);

        $this->assertSame($counts, $this->counts());
    }

    /**
     * @return array<string, array{array<string, mixed>, int, string}>
     */
    public static function acceptedInputs(): array
    {
        return [
            'BOM つきのファイル' => [['file' => ['a.txt', "\u{FEFF}発言"]], 1, '発言'],
            'BOM つきの .vtt' => [['file' => ['a.vtt', "\u{FEFF}WEBVTT\n\n00:01.000 --> 00:02.000\n発言"]], 1, '発言'],
            'ファイルが 10,000 行' => [['file' => ['a.txt', str_repeat("行\n", 10_000)]], 10_000, '行'],
            '貼り付けが 10,000 行' => [['text' => str_repeat("行\n", 10_000)], 10_000, '行'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('acceptedInputs')]
    public function test_accepts_a_bom_and_the_line_limit(array $input, int $lineCount, string $firstText): void
    {
        $this->postMeeting($input)->assertStatus(303)->assertSessionHasNoErrors();

        $transcriptId = Transcript::query()->where('record_id', $this->latestMeetingId())->sole()->id;
        $segments = TranscriptSegment::query()->where('transcript_id', $transcriptId);
        $this->assertSame([$lineCount, $firstText], [$segments->count(), $segments->orderBy('seq')->value('text')]);
    }

    public function test_a_file_name_of_256_characters_is_cut_to_255_for_the_transcript_and_the_title(): void
    {
        $name = str_repeat('あ', 252).'.txt';

        $this->postMeeting(['file' => [$name, '発言']])->assertSessionHasNoErrors();

        $cut = mb_substr($name, 0, 255);
        $this->assertSame($cut, Transcript::query()->where('record_id', $this->latestMeetingId())->sole()->file_name);
        $this->assertSame($cut, $this->valuesOf($this->latestMeetingId())['title']);
    }
}
