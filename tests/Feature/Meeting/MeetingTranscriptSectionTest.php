<?php

namespace Tests\Feature\Meeting;

use App\Actions\Meeting\MeetingTranscriptWriter;
use App\Actions\Meeting\TranscriptLine;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Meeting\TranscriptSource;
use App\Models\Master\User;
use App\Models\Meeting\Transcript;
use App\Queries\Meeting\MeetingTranscriptSection;
use App\Queries\Records\Api\RecordShowPanelQuery;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TransactionalTestCase;
use Tests\Support\UsesSeededRecords;

/**
 * 会議の詳細の「書き起こし」の節(docs の 設計/業務ツール/会議/公開デモと画面.md の 9.3、形 = 設計/業務データの入れ物/レコードの共通機能/
 * 詳細の画面/節の口.md の 1 節・呼び方 = 2 節): 帯は件数だけ、開くと発言の行の全部。書き起こしの無い会議と会議でない業務モジュールでは出さない。
 */
final class MeetingTranscriptSectionTest extends TransactionalTestCase
{
    use UsesMeetings;
    use UsesSeededRecords;

    private const DESCRIPTOR = ['code' => 'meeting-transcript', 'heading' => '書き起こし', 'shortHeading' => '発言'];

    private const CHUNKED_NOTE = '長い音声を区切って書き起こしたため、区切りごとに話者のラベルが振り直されています。';

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMeetings();
        $this->creator = $this->user();
    }

    public function test_the_band_carries_the_count_of_the_lines_without_the_contents(): void
    {
        $recordId = $this->pastedMeeting($this->creator, "一\n二\n三");

        $this->actingAs($this->creator)->get("/records/data/meeting/{$recordId}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sections', [[...self::DESCRIPTOR, 'count' => 3]])
                ->where('panelContent', null)
            );
    }

    public function test_opening_the_section_returns_every_line_in_order_with_the_time_and_the_speaker(): void
    {
        $recordId = $this->manualMeeting();
        app(MeetingTranscriptWriter::class)->write($recordId, TranscriptSource::TextFile, '定例.vtt', [
            new TranscriptLine('始めます。', 'A', '0.000', '4.500'),
            new TranscriptLine('時刻だけの行', null, '3725.999', '3730.000'),
            new TranscriptLine('話者だけの行', '佐藤'),
            new TranscriptLine('どちらも無い行'),
        ]);

        $this->actingAs($this->creator)->get("/records/data/meeting/{$recordId}?panel=meeting-transcript")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('panelContent', [
                'kind' => 'section',
                'code' => 'meeting-transcript',
                'content' => [
                    'kind' => 'lines',
                    'count' => 4,
                    'note' => null,
                    'items' => [
                        ['time' => '00:00:00', 'label' => '話者 A', 'text' => '始めます。'],
                        ['time' => '01:02:05', 'label' => null, 'text' => '時刻だけの行'],
                        ['time' => null, 'label' => '話者 佐藤', 'text' => '話者だけの行'],
                        ['time' => null, 'label' => null, 'text' => 'どちらも無い行'],
                    ],
                    'emptyText' => '発言はありません。',
                ],
            ]));
    }

    public function test_the_note_is_shown_only_when_the_transcript_was_written_in_two_or_more_chunks(): void
    {
        $chunked = $this->audioMeeting(2);
        $single = $this->audioMeeting(1);

        $this->assertSame(
            [self::CHUNKED_NOTE, null],
            [$this->noteOf($chunked), $this->noteOf($single)],
        );
    }

    public function test_a_meeting_without_a_transcript_and_another_module_have_no_section(): void
    {
        $manual = $this->manualMeeting();
        $project = $this->recordNamed('project', 'name', '社内ポータル刷新');
        $context = $this->contextOf($this->creator);

        $this->assertSame([null, null], [
            $this->section()->presence($context, 'meeting', $manual),
            $this->section()->section($context, 'meeting', $manual),
        ]);
        $this->assertSame([null, null], [
            $this->section()->presence($context, 'project', $project->id),
            $this->section()->section($context, 'project', $project->id),
        ]);
        $this->actingAs($this->creator)->get("/records/data/meeting/{$manual}")
            ->assertInertia(fn (Assert $page) => $page->where('sections', []));
    }

    public function test_the_section_of_a_meeting_the_viewer_cannot_view_is_not_returned(): void
    {
        $recordId = $this->pastedMeeting($this->creator);
        $outsider = $this->user();
        $panels = app(RecordShowPanelQuery::class);
        $content = fn (User $viewer) => $panels
            ->content($this->contextOf($viewer), 'meeting', $recordId, 'meeting-transcript', null);

        $this->assertNull($content($outsider));
        $this->actingAs($outsider)->get("/records/data/meeting/{$recordId}?panel=meeting-transcript")->assertNotFound();
        $this->assertSame(
            ['kind' => 'section', 'code' => 'meeting-transcript'],
            array_intersect_key(
                $content($this->creator) ?? [],
                ['kind' => true, 'code' => true],
            ),
        );
    }

    private function section(): MeetingTranscriptSection
    {
        return app(MeetingTranscriptSection::class);
    }

    /** 会議の節の頭の注(節が無ければ失敗) */
    private function noteOf(int $recordId): ?string
    {
        $section = $this->section()->section($this->contextOf($this->creator), 'meeting', $recordId);

        if ($section === null) {
            $this->fail("会議 {$recordId} の節が無い");
        }

        return $section['note'];
    }

    /** 入れ物の新規登録で作った会議(書き起こしが無い) */
    private function manualMeeting(): int
    {
        $input = new RecordInput(['title' => '手で作った会議']);

        return app(CreateRecord::class)->execute($this->contextOf($this->creator), 'meeting', $input);
    }

    /** 音声の書き起こし(区切りの数 $chunkCount・発言 1 行)を持つ会議 */
    private function audioMeeting(int $chunkCount): int
    {
        $recordId = $this->manualMeeting();
        Transcript::factory()->audio($chunkCount)->hasSegments(1)->create(['record_id' => $recordId]);

        return $recordId;
    }
}
