<?php

namespace App\Models\Meeting;

use App\Enums\Meeting\MeetingRunDecline;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Models\Records\Record;
use Database\Factories\Meeting\MeetingRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 会議と AI の実行の対応(会議の段ごと・再作成ごとの依頼 1 つ)。表 meeting_runs(docs の 設計/業務ツール/会議/README.md の 2 節、
 * 読み方 = 設計/業務ツール/会議/処理.md の 3.7・3.8)。会議の最後の対応 = その会議の対応のうち id が最大の行。
 * 実行の無い行は断られた依頼で、理由と依頼した人を持つ(実行のある行の依頼した人は実行の記録が正本。列の組は検査制約が止める)。
 */
#[Fillable(['record_id', 'ai_run_id', 'stage', 'origin', 'writes_title', 'declined_code', 'requester_user_id'])]
class MeetingRun extends Model
{
    /** @use HasFactory<MeetingRunFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => MeetingRunStage::class,
            'origin' => MeetingRunOrigin::class,
            'writes_title' => 'boolean',
            'declined_code' => MeetingRunDecline::class,
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
     * AI の実行(断られた依頼は null)。
     *
     * @return BelongsTo<AiRun, $this>
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    /**
     * 断られた依頼を依頼した人(実行のある行は null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }
}
