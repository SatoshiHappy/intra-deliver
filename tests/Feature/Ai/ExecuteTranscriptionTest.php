<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\TranscribedSegment;
use App\Actions\Ai\ExecuteTranscription;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunText;
use Laravel\Ai\Prompts\TranscriptionPrompt;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Transcription;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 文字起こしの実行の成功(docs の 設計/AI/実行と費用.md 4 節・4.2・5.1): 区切りごとに順に呼び、区間の時刻を区切りの始まりの分ずらし、区切りが 2 つ以上なら
 * 話者のラベルに区切りの番号を添える。区切りの数・音声の秒数・費用(呼んだ区切りの秒 ÷ 60 × 0.005 ドル)・使用量の和・原文の JSON・受け手の書き込みが
 * 成功と同時に見え、一時ファイルは残らない。区間が 0 の区切りは、全体が無音なら完全として扱う。接続先は laravel/ai の差し替えで、本物を呼ばない。
 */
final class ExecuteTranscriptionTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use UsesTestTranscriptionKinds;

    /** 無音 7〜9 秒・15〜17 秒の 25 秒(区切り = 0〜8・8〜16・16〜25 秒) */
    private const PAUSED_SPEECH = 'if(between(t,7,9)+between(t,15,17),0,sin(2*PI*440*t))';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestTranscriptionKind();
    }

    protected function tearDown(): void
    {
        $this->removeTranscriptionTemp();
        parent::tearDown();
    }

    public function test_the_segments_of_the_chunks_are_shifted_labelled_and_written_with_the_cost(): void
    {
        $runId = $this->queuedTranscription($this->audioUpload(self::PAUSED_SPEECH, 25));
        Transcription::fake([
            $this->transcriptionResponse([['一つ目', 'A', 0.5, 6.5], ['二つ目', 'B', 6.6, 7.2]]),
            $this->transcriptionResponse([['三つ目', 'A', 0.2, 6.8]]),
            $this->transcriptionResponse([['四つ目', 'B', 0.1, 8.9]]),
        ]);

        app(ExecuteTranscription::class)->execute($runId);

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame([AiRunStatus::Succeeded, '0.002083'], [$run->status, $run->cost_usd]);
        $this->assertSame([30, 15], [$run->input_tokens, $run->output_tokens]);
        $this->assertSame(['per_audio_minute' => ['input' => 0.003, 'output' => 0.002]], $run->pricing);
        $completed = $this->transcriptionKind->completed;
        $this->assertCount(1, $completed);
        $this->assertSame([3, 25.0], [$completed[0]->chunkCount, $completed[0]->audioSeconds]);
        $this->assertSame($run->requester_user_id, $completed[0]->requester->userId);
        $this->assertSegments([
            ['一つ目', '1-A', 0.5, 6.5],
            ['二つ目', '1-B', 6.6, 7.2],
            ['三つ目', '2-A', 8.2, 14.8],
            ['四つ目', '3-B', 16.1, 24.9],
        ], $completed[0]->segments);
        $this->assertSame(['running'], $this->transcriptionKind->statusesSeen);
        $output = json_decode((string) AiRunText::query()->findOrFail($runId)->output_text, true);
        $this->assertSame(['一つ目', '1-A'], [$output[0]['text'], $output[0]['speaker_label']]);
        $this->assertEqualsWithDelta(16.1, $output[3]['start_seconds'], 0.1);
        $this->assertNoTranscriptionFilesLeft();
    }

    public function test_each_chunk_is_sent_as_mp3_with_the_language_diarization_and_time_limit_of_the_kind(): void
    {
        $runId = $this->queuedTranscription($this->audioUpload(self::PAUSED_SPEECH, 25));
        $prompts = [];
        Transcription::fake(function (TranscriptionPrompt $prompt) use (&$prompts): TranscriptionResponse {
            $prompts[] = [
                $prompt->audio->mimeType(), $prompt->language, $prompt->isDiarized(), $prompt->timeout, $prompt->model,
            ];

            return $this->transcriptionResponse([['発言', 'A', 0, 7.5]]);
        });

        app(ExecuteTranscription::class)->execute($runId);

        $this->assertSame(array_fill(0, 3, ['audio/mpeg', 'ja', true, 30, 'gemini-3.5-transcribe']), $prompts);
    }

    public function test_a_single_chunk_keeps_the_labels_of_the_provider(): void
    {
        $runId = $this->queuedTranscription($this->audioUpload('sin(2*PI*440*t)', 6));
        Transcription::fake([$this->transcriptionResponse([['発言', 'A', 0, 5.9]])]);

        app(ExecuteTranscription::class)->execute($runId);

        $completed = $this->transcriptionKind->completed[0];
        $this->assertSame([1, 6.0], [$completed->chunkCount, $completed->audioSeconds]);
        $this->assertSame('0.000500', AiRun::query()->findOrFail($runId)->cost_usd);
        $this->assertSegments([['発言', 'A', 0.0, 5.9]], $completed->segments);
    }

    /** 音は 0〜10 秒だけ(区切り = 0〜10・10〜17.5・17.5〜25 秒)。後の 2 つは全体が無音なので、区間が 0 でも完全 */
    public function test_a_silent_chunk_without_segments_is_complete(): void
    {
        $runId = $this->queuedTranscription($this->audioUpload('if(lt(t,10),sin(2*PI*440*t),0)', 25));
        Transcription::fake([
            $this->transcriptionResponse([['発言', 'A', 0, 9.8]]),
            $this->transcriptionResponse([]),
            $this->transcriptionResponse([]),
        ]);

        app(ExecuteTranscription::class)->execute($runId);

        $this->assertSame(AiRunStatus::Succeeded, AiRun::query()->findOrFail($runId)->status);
        $this->assertSegments([['発言', '1-A', 0.0, 9.8]], $this->transcriptionKind->completed[0]->segments);
    }

    /**
     * @param  list<array{string, string, float, float}>  $expected
     * @param  list<TranscribedSegment>  $segments
     */
    private function assertSegments(array $expected, array $segments): void
    {
        $this->assertCount(count($expected), $segments);

        foreach ($segments as $index => $segment) {
            [$text, $label, $start, $end] = $expected[$index];
            $this->assertSame([$text, $label], [$segment->text, $segment->speakerLabel]);
            $this->assertEqualsWithDelta($start, $segment->startSeconds, 0.1);
            $this->assertEqualsWithDelta($end, $segment->endSeconds, 0.1);
        }
    }
}
