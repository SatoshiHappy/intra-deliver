<?php

namespace Database\Factories\Meeting;

use App\Models\Meeting\Transcript;
use App\Models\Meeting\TranscriptSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は話者と秒の無い 1 行目の発言。
 *
 * @extends Factory<TranscriptSegment>
 */
class TranscriptSegmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transcript_id' => Transcript::factory(),
            'seq' => 1,
            'speaker_label' => null,
            'started_at_sec' => null,
            'ended_at_sec' => null,
            'text' => '今日の議題を確認します。',
        ];
    }
}
