<?php

namespace Database\Factories\Meeting;

use App\Enums\Meeting\MeetingRunDecline;
use App\Enums\Meeting\MeetingRunOrigin;
use App\Enums\Meeting\MeetingRunStage;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use App\Models\Meeting\MeetingRun;
use App\Models\Records\Record;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は作成の議事録の実行の対応(AI は件名を書かない)。
 *
 * @extends Factory<MeetingRun>
 */
class MeetingRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'record_id' => Record::factory(),
            'ai_run_id' => AiRun::factory(),
            'stage' => MeetingRunStage::Minutes,
            'origin' => MeetingRunOrigin::Create,
            'writes_title' => false,
            'declined_code' => null,
            'requester_user_id' => null,
        ];
    }

    /** 断られた議事録の依頼の対応(実行が無く、理由と依頼した人を持つ) */
    public function declined(MeetingRunDecline $reason = MeetingRunDecline::Budget): static
    {
        return $this->state(fn (): array => [
            'ai_run_id' => null,
            'stage' => MeetingRunStage::Minutes,
            'declined_code' => $reason,
            'requester_user_id' => User::factory(),
        ]);
    }
}
