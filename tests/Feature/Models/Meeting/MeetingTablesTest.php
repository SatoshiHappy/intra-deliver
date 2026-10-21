<?php

namespace Tests\Feature\Models\Meeting;

use App\Actions\Ai\Api\RunRequestRejected;
use App\Enums\Meeting\MeetingRunDecline;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Enums\Meeting\TranscriptSource;
use App\Models\Ai\AiRun;
use App\Models\Meeting\MeetingRun;
use App\Models\Meeting\Transcript;
use App\Models\Meeting\TranscriptSegment;
use App\Models\Records\Record;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 会議の 3 表の制約(列と外部キーの索引は SchemaTest。docs の 設計/業務ツール/会議/README.md の 2 節、処理.md の 3.7・3.8):
 * 出どころが決める列の組、発言の行の空と秒、対応の断られた行の列の組・再作成の件名の印・断られる段。会議のレコードを消すと 3 表の行が消え、AI の実行は残る。
 */
final class MeetingTablesTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_the_source_decides_the_file_name_the_run_and_the_chunks(): void
    {
        $pasted = $this->transcript([])->id;
        $this->assertRejectedBy(
            'transcripts_source_check',
            fn () => DB::table('transcripts')->where('id', $pasted)->update(['source' => 'video']),
        );
        $this->assertRejectedBy(
            'transcripts_source_file_name_check',
            fn () => $this->transcript(['file_name' => 'memo.txt']),
        );
        $this->assertRejectedBy(
            'transcripts_source_file_name_check',
            fn () => $this->transcript(['source' => TranscriptSource::TextFile]),
        );
        $this->assertRejectedBy(
            'transcripts_source_ai_run_id_check',
            fn () => Transcript::factory()->audio()->create(['ai_run_id' => null]),
        );
        $this->assertRejectedBy(
            'transcripts_source_ai_run_id_check',
            fn () => $this->transcript(['ai_run_id' => $this->aiRunId()]),
        );
        $this->assertRejectedBy(
            'transcripts_chunk_count_source_check',
            fn () => Transcript::factory()->audio(0)->create(),
        );
        $this->assertRejectedBy(
            'transcripts_chunk_count_source_check',
            fn () => Transcript::factory()->textFile()->create(['chunk_count' => 2]),
        );

        $audio = Transcript::factory()->audio(3)->create();
        $this->assertSame([TranscriptSource::Audio, 3], [$audio->refresh()->source, $audio->chunk_count]);
        $this->assertRejectedBy(
            'transcripts_record_id_unique',
            fn () => $this->transcript(['record_id' => $audio->record_id]),
        );
    }

    public function test_a_segment_has_a_text_and_non_negative_ordered_seconds(): void
    {
        $transcript = Transcript::factory()->create();
        $segment = fn (array $attributes) => TranscriptSegment::factory()->for($transcript)->create($attributes);

        $this->assertRejectedBy('transcript_segments_seq_check', fn () => $segment(['seq' => 0]));
        $this->assertRejectedBy('transcript_segments_text_check', fn () => $segment(['text' => '']));
        $this->assertRejectedBy('transcript_segments_speaker_label_check', fn () => $segment(['speaker_label' => '']));
        $this->assertRejectedBy(
            'transcript_segments_started_at_sec_check',
            fn () => $segment(['started_at_sec' => '-0.001']),
        );
        $this->assertRejectedBy(
            'transcript_segments_ended_at_sec_check',
            fn () => $segment(['ended_at_sec' => '-0.001']),
        );
        $this->assertRejectedBy(
            'transcript_segments_started_at_sec_ended_at_sec_check',
            fn () => $segment(['started_at_sec' => '10.500', 'ended_at_sec' => '10.499']),
        );

        $first = $segment(['speaker_label' => '話者 1', 'started_at_sec' => '0', 'ended_at_sec' => '0']);
        $second = $segment(['seq' => 2, 'started_at_sec' => null, 'ended_at_sec' => '3.250']);
        $this->assertSame(['0.000', '3.250'], [$first->refresh()->ended_at_sec, $second->refresh()->ended_at_sec]);
        $this->assertRejectedBy('transcript_segments_transcript_id_seq_unique', fn () => $segment(['seq' => 2]));
    }

    public function test_a_declined_run_has_a_reason_and_a_requester_and_no_run(): void
    {
        $runWithout = fn (string $column) => MeetingRun::factory()->declined()->create([$column => null]);
        $this->assertRejectedBy(
            'meeting_runs_ai_run_id_declined_code_check',
            fn () => $this->meetingRun(['declined_code' => 'budget']),
        );
        $this->assertRejectedBy('meeting_runs_ai_run_id_declined_code_check', fn () => $runWithout('declined_code'));
        $this->assertRejectedBy(
            'meeting_runs_ai_run_id_requester_user_id_check',
            fn () => $runWithout('requester_user_id'),
        );
        $requester = AiRun::factory()->create()->requester_user_id;
        $this->assertRejectedBy(
            'meeting_runs_ai_run_id_requester_user_id_check',
            fn () => $this->meetingRun(['requester_user_id' => $requester]),
        );
        $declinedId = MeetingRun::factory()->declined()->create()->id;
        $this->assertRejectedBy(
            'meeting_runs_declined_code_check',
            fn () => $this->updateRun($declinedId, ['declined_code' => 'quota']),
        );
        $this->assertRejectedBy(
            'meeting_runs_declined_code_stage_check',
            fn () => $this->updateRun($declinedId, ['stage' => 'transcription']),
        );

        $tooLong = MeetingRun::factory()->declined(MeetingRunDecline::TooLong)->create()->refresh();
        $this->assertSame([null, MeetingRunDecline::TooLong], [$tooLong->ai_run_id, $tooLong->declined_code]);
    }

    public function test_only_a_create_writes_the_title_and_a_run_has_one_meeting_run(): void
    {
        $runId = $this->meetingRun([])->id;
        $this->assertRejectedBy('meeting_runs_stage_check', fn () => $this->updateRun($runId, ['stage' => 'summary']));
        $this->assertRejectedBy('meeting_runs_origin_check', fn () => $this->updateRun($runId, ['origin' => 'retry']));
        $this->assertRejectedBy(
            'meeting_runs_origin_writes_title_check',
            fn () => $this->meetingRun(['origin' => MeetingRunOrigin::Regenerate, 'writes_title' => true]),
        );

        $created = $this->meetingRun(['stage' => MeetingRunStage::Transcription, 'writes_title' => true]);
        $this->assertTrue($created->refresh()->writes_title);
        $this->assertRejectedBy(
            'meeting_runs_ai_run_id_unique',
            fn () => $this->meetingRun(['ai_run_id' => $created->ai_run_id]),
        );
    }

    public function test_deleting_the_meeting_deletes_its_rows_and_keeps_the_runs(): void
    {
        $record = Record::factory()->create();
        $transcript = Transcript::factory()->audio()->for($record)->create();
        $segment = TranscriptSegment::factory()->for($transcript)->create();
        $run = MeetingRun::factory()->for($record)->create();
        $declined = MeetingRun::factory()->declined()->for($record)->create();

        DB::table('records')->where('id', $record->id)->delete();

        foreach ([$transcript, $segment, $run, $declined] as $row) {
            $this->assertModelMissing($row);
        }

        $this->assertSame(2, AiRun::query()->whereKey([$transcript->ai_run_id, $run->ai_run_id])->count());
    }

    public function test_the_decline_reasons_are_the_reasons_of_the_ai_request(): void
    {
        $this->assertSame(
            [RunRequestRejected::BUDGET, RunRequestRejected::TOO_LONG],
            array_map(fn (MeetingRunDecline $reason) => $reason->value, MeetingRunDecline::cases()),
        );
    }

    private function aiRunId(): int
    {
        return AiRun::factory()->create()->id;
    }

    /**
     * 作成の議事録の実行の対応(既定)に属性を重ねて作る。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function meetingRun(array $attributes): MeetingRun
    {
        return MeetingRun::factory()->create($attributes);
    }

    /**
     * 列挙を通さずに対応の列を書き換える(検査制約に無い値を書くため)。
     *
     * @param  array<string, mixed>  $values
     */
    private function updateRun(int $id, array $values): int
    {
        return DB::table('meeting_runs')->where('id', $id)->update($values);
    }

    /**
     * 貼り付けの書き起こし(既定)に属性を重ねて作る。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transcript(array $attributes): Transcript
    {
        return Transcript::factory()->create($attributes);
    }
}
