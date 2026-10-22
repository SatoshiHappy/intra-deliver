<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Upload\Upload;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * モジュール Ai の 2 表の制約と外部キー(docs の 設計/AI/README.md 2 節): 状態ごとに持つ列の組、使用量・単価・費用は揃って書く、
 * 業務レコードを消しても実行は残り(SET NULL)、元の ID と業務モジュールのコードは残る、実行を消すと原文も消える(CASCADE)、
 * 依頼者の利用者は消せない(RESTRICT)。文字起こしの行は音声の長さと元の音声を持ち出力の上限を持たない(文章の行はその逆)、
 * 音声の実体の行を消すと指す先が NULL になり元の ID が残る、使用量のトークンが分からなくても単価と費用を持てる。
 */
final class AiTablesTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;
    use BuildsRecordModules;

    public function test_the_status_decides_the_columns_of_a_run(): void
    {
        $this->assertRejectedBy('ai_runs_status_check', fn () => DB::table('ai_runs')->where('id', $this->aiRun()->id)->update(['status' => 'done']));
        $this->assertRejectedBy('ai_runs_status_started_at_check', fn () => $this->aiRun(['status' => AiRunStatus::Running]));
        $this->assertRejectedBy('ai_runs_status_started_at_check', fn () => $this->aiRun(['started_at' => now()]));
        $this->assertRejectedBy('ai_runs_status_finished_at_check', fn () => $this->aiRun(['status' => AiRunStatus::Failed, 'error_code' => 'provider', 'error_message' => 'x']));
        $this->assertRejectedBy('ai_runs_status_error_code_error_message_check', fn () => $this->aiRun(['status' => AiRunStatus::Failed, 'finished_at' => now()]));
        $failed = $this->aiRun(['status' => AiRunStatus::Failed, 'finished_at' => now(), 'error_code' => 'provider', 'error_message' => 'x']);
        $this->assertRejectedBy('ai_runs_error_code_check', fn () => DB::table('ai_runs')->where('id', $failed->id)->update(['error_code' => 'other']));
        $this->assertRejectedBy('ai_runs_status_cost_usd_check', fn () => $this->aiRun(['status' => AiRunStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()]));

        $failedBeforeStart = $this->aiRun(['status' => AiRunStatus::Failed, 'finished_at' => now(), 'error_code' => 'worker_lost', 'error_message' => 'x']);
        $this->assertNull($failedBeforeStart->started_at);
    }

    public function test_usage_pricing_and_cost_are_written_together(): void
    {
        $this->assertRejectedBy('ai_runs_usage_check', fn () => $this->aiRun(['input_tokens' => 1, 'output_tokens' => 1]));
        $this->assertRejectedBy('ai_runs_usage_breakdown_check', fn () => $this->aiRun(['cache_read_input_tokens' => 0]));
        $this->assertRejectedBy('ai_runs_tokens_check', fn () => $this->aiRun(['input_tokens' => -1, 'output_tokens' => 1, 'pricing' => ['input' => 1], 'cost_usd' => '0']));
        $this->assertRejectedBy('ai_runs_max_output_tokens_check', fn () => $this->aiRun(['max_output_tokens' => 0]));
        $this->assertRejectedBy('ai_runs_source_record_ids_check', fn () => $this->aiRun(['source_record_ids' => ['a' => 1]]));

        $run = $this->aiRun(['input_tokens' => 10, 'output_tokens' => 5, 'pricing' => ['input' => 1], 'cost_usd' => '0.000015']);
        $this->assertSame('0.000015', $run->refresh()->cost_usd);
    }

    public function test_the_target_record_keeps_its_original_id_and_module_code_after_deletion(): void
    {
        $record = Record::factory()->create(['record_module_id' => $this->recordModule('meetings')->id]);
        $this->assertRejectedBy('ai_runs_record_id_original_record_id_check', fn () => $this->aiRun(['record_id' => $record->id, 'original_record_id' => $record->id + 1, 'record_module_code' => 'meetings']));
        $this->assertRejectedBy('ai_runs_original_record_id_record_module_code_check', fn () => $this->aiRun(['record_id' => $record->id, 'original_record_id' => $record->id]));
        $run = $this->aiRun(['record_id' => $record->id, 'original_record_id' => $record->id, 'record_module_code' => 'meetings']);

        DB::table('records')->where('id', $record->id)->delete();

        $this->assertSame([null, $record->id, 'meetings'], [$run->refresh()->record_id, $run->original_record_id, $run->record_module_code]);
    }

    public function test_deleting_a_run_deletes_its_text_and_the_requester_cannot_be_deleted(): void
    {
        $run = $this->aiRun(['requester_user_id' => User::factory()->create()->id]);
        AiRunText::factory()->for($run)->create();

        $this->assertRejectedBy('ai_runs_requester_user_id_foreign', fn () => DB::table('users')->where('id', $run->requester_user_id)->delete());

        DB::table('ai_runs')->where('id', $run->id)->delete();
        $this->assertSame(0, AiRunText::query()->whereKey($run->id)->count());
    }

    public function test_the_type_columns_separate_prompt_runs_from_transcription_runs(): void
    {
        $uploadId = Upload::factory()->create()->id;
        $audio = ['original_input_upload_id' => $uploadId, 'audio_seconds' => '90.500'];
        $check = 'ai_runs_type_columns_check';
        $this->assertRejectedBy($check, fn () => $this->aiRun(['max_output_tokens' => null]));
        $this->assertRejectedBy($check, fn () => $this->aiRun(['audio_seconds' => '1.000']));
        $this->assertRejectedBy($check, fn () => $this->aiRun(['original_input_upload_id' => $uploadId]));
        $this->assertRejectedBy($check, fn () => $this->aiRun($audio));
        $this->assertRejectedBy($check, fn () => $this->transcriptionRun(['audio_seconds' => null]));
        $noAudio = ['original_input_upload_id' => null, 'input_upload_id' => null];
        $this->assertRejectedBy($check, fn () => $this->transcriptionRun($noAudio));
        $positive = 'ai_runs_audio_seconds_check';
        $this->assertRejectedBy($positive, fn () => $this->transcriptionRun(['audio_seconds' => '0']));
        $this->assertRejectedBy($positive, fn () => $this->transcriptionRun(['audio_seconds' => '-1']));

        $run = $this->transcriptionRun(['input_upload_id' => $uploadId, ...$audio]);
        $this->assertSame([null, '90.500'], [$run->refresh()->max_output_tokens, $run->audio_seconds]);
    }

    public function test_the_audio_keeps_its_original_id_after_the_upload_is_deleted(): void
    {
        $upload = Upload::factory()->create();
        $other = Upload::factory()->create();
        $mismatched = ['input_upload_id' => $upload->id, 'original_input_upload_id' => $other->id];
        $this->assertRejectedBy(
            'ai_runs_input_upload_id_original_input_upload_id_check',
            fn () => $this->transcriptionRun($mismatched),
        );
        $run = $this->transcriptionRun(['input_upload_id' => $upload->id, 'original_input_upload_id' => $upload->id]);

        DB::table('uploads')->where('id', $upload->id)->delete();

        $this->assertSame([null, $upload->id], [$run->refresh()->input_upload_id, $run->original_input_upload_id]);
    }

    public function test_a_transcription_run_has_a_cost_without_token_usage(): void
    {
        $succeeded = ['status' => AiRunStatus::Succeeded, 'started_at' => now(), 'finished_at' => now()];
        $pricing = ['per_audio_minute' => ['input' => 0.003, 'output' => 0.002]];
        $priced = ['pricing' => $pricing, 'cost_usd' => '0.007500'];
        $tokens = ['input_tokens' => 1, 'output_tokens' => 1];
        $this->assertRejectedBy('ai_runs_usage_check', fn () => $this->transcriptionRun(['pricing' => $pricing]));
        $this->assertRejectedBy('ai_runs_usage_check', fn () => $this->transcriptionRun($tokens));
        $this->assertRejectedBy('ai_runs_usage_check', fn () => $this->aiRun($priced));
        $this->assertRejectedBy('ai_runs_status_cost_usd_check', fn () => $this->transcriptionRun($succeeded));

        $run = $this->transcriptionRun([...$succeeded, ...$priced]);
        $this->assertSame([null, '0.007500'], [$run->refresh()->input_tokens, $run->cost_usd]);
        $this->transcriptionRun([...$succeeded, ...$priced, 'input_tokens' => 10, 'output_tokens' => 5]);
    }

    public function test_a_conversion_failure_is_an_error_code(): void
    {
        $failed = ['status' => AiRunStatus::Failed, 'finished_at' => now(), 'error_message' => 'x'];

        $run = $this->transcriptionRun([...$failed, 'error_code' => 'conversion']);

        $this->assertSame(AiRunErrorCode::Conversion, $run->refresh()->error_code);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function aiRun(array $attributes = []): AiRun
    {
        return AiRun::factory()->create($attributes);
    }

    /**
     * 文字起こしの待ちの実行(音声 60 秒・出力の上限なし)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transcriptionRun(array $attributes = []): AiRun
    {
        $uploadId = Upload::factory()->create()->id;

        return $this->aiRun([
            'kind' => 'meeting.transcription',
            'provider' => 'gemini',
            'model' => 'gemini-3.5-transcribe',
            'budget' => 'transcription',
            'max_output_tokens' => null,
            'input_upload_id' => $uploadId,
            'original_input_upload_id' => $uploadId,
            'audio_seconds' => '60.000',
            ...$attributes,
        ]);
    }
}
