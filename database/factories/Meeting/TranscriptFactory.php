<?php

namespace Database\Factories\Meeting;

use App\Enums\Meeting\TranscriptSource;
use App\Models\Ai\AiRun;
use App\Models\Meeting\Transcript;
use App\Models\Records\Record;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は貼り付けの書き起こし(ファイルの名前・文字起こしの実行なし)。
 *
 * @extends Factory<Transcript>
 */
class TranscriptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_id' => Record::factory(),
            'source' => TranscriptSource::Paste,
            'file_name' => null,
            'language' => 'ja',
            'ai_run_id' => null,
            'chunk_count' => 1,
        ];
    }

    /** テキストのファイルの書き起こし */
    public function textFile(string $fileName = '定例会議.txt'): static
    {
        return $this->state(['source' => TranscriptSource::TextFile, 'file_name' => $fileName]);
    }

    /** 音声の書き起こし(文字起こしの実行と、区切って書き起こした数を持つ) */
    public function audio(int $chunkCount = 1): static
    {
        return $this->state(fn (): array => [
            'source' => TranscriptSource::Audio,
            'file_name' => '定例会議.m4a',
            'ai_run_id' => AiRun::factory(),
            'chunk_count' => $chunkCount,
        ]);
    }
}
