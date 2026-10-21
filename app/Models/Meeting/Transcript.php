<?php

namespace App\Models\Meeting;

use App\Enums\Meeting\TranscriptSource;
use App\Models\Ai\AiRun;
use App\Models\Records\Record;
use Database\Factories\Meeting\TranscriptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 会議の書き起こし(会議 1 つに 1 行)。表 transcripts(docs の 設計/業務ツール/会議/README.md の 2 節)。
 * 文字起こしの実行と区切った数は音声のときだけ持つ(列の組は検査制約が止める)。
 */
#[Fillable(['record_id', 'source', 'file_name', 'language', 'ai_run_id', 'chunk_count'])]
class Transcript extends Model
{
    /** @use HasFactory<TranscriptFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => TranscriptSource::class,
            'chunk_count' => 'integer',
        ];
    }

    /**
     * 会議のレコード。
     *
     * @return BelongsTo<Record, $this>
     */
    public function record(): BelongsTo
    {
        return $this->belongsTo(Record::class);
    }

    /**
     * 書き起こしを作った文字起こしの実行(音声だけ。ほかは null)。
     *
     * @return BelongsTo<AiRun, $this>
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    /**
     * 発言の行。
     *
     * @return HasMany<TranscriptSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class);
    }
}
