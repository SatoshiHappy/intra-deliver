<?php

namespace App\Models\Meeting;

use Database\Factories\Meeting\TranscriptSegmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 書き起こしの発言の行(並び seq は 1 から)。表 transcript_segments(docs の 設計/業務ツール/会議/README.md の 2 節)。
 * 話者のラベルと開始・終了の秒は、出どころが持たなければ null。
 */
#[Fillable(['transcript_id', 'seq', 'speaker_label', 'started_at_sec', 'ended_at_sec', 'text'])]
class TranscriptSegment extends Model
{
    /** @use HasFactory<TranscriptSegmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'started_at_sec' => 'decimal:3',
            'ended_at_sec' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Transcript, $this>
     */
    public function transcript(): BelongsTo
    {
        return $this->belongsTo(Transcript::class);
    }
}
