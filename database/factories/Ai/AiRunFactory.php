<?php

namespace Database\Factories\Ai;

use App\Enums\Ai\AiKindType;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Models\Ai\AiAgentDefinition;
use App\Models\Ai\AiRun;
use App\Models\Master\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は queued の実行(対象のレコードなし。公開デモの meeting.minutes の設定を写した形)。種類の型は、渡した属性に音声の長さがあれば文字起こし、
 * 無ければ文章(前からある行の埋め方と同じ = マイグレーション add_conversation_to_ai_runs)。会話の型の行は {@see self::conversation()}。
 *
 * @extends Factory<AiRun>
 */
class AiRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => 'meeting.minutes',
            'kind_type' => fn (array $attributes): AiKindType => isset($attributes['audio_seconds'])
                ? AiKindType::Transcription
                : AiKindType::Prompt,
            'status' => AiRunStatus::Queued,
            'requester_user_id' => User::factory(),
            'selected_department_id' => null,
            'record_id' => null,
            'original_record_id' => null,
            'record_module_code' => null,
            'source_record_ids' => [],
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'budget' => 'default',
            'max_output_tokens' => 100,
            'reserved_cost_usd' => '0.010000',
        ];
    }

    /**
     * 会話の型の待ちの実行(定義は新しく作る。題を求めない最初の文の実行。接続先・モデル・見積もり・送ったデータを読んだレコードを持たない)。
     */
    public function conversation(): static
    {
        return $this->state(fn (): array => [
            'kind' => 'test.conversation',
            'kind_type' => AiKindType::Conversation,
            'agent_definition_id' => AiAgentDefinition::factory(),
            'wants_title' => false,
            'source_record_ids' => null,
            'provider' => null,
            'model' => null,
            'max_output_tokens' => null,
            'reserved_cost_usd' => null,
        ]);
    }

    /** running の実行 */
    public function running(): static
    {
        return $this->state(fn (): array => ['status' => AiRunStatus::Running, 'started_at' => now()]);
    }

    /** 使用量の分かった succeeded の実行 */
    public function succeeded(string $costUsd = '0.001000'): static
    {
        return $this->state(fn (): array => [
            'status' => AiRunStatus::Succeeded,
            'started_at' => now(),
            'finished_at' => now(),
            'input_tokens' => 1,
            'output_tokens' => 1,
            'pricing' => ['input' => 0.1],
            'cost_usd' => $costUsd,
        ]);
    }

    /** 使用量の分からない failed の実行 */
    public function failed(AiRunErrorCode $errorCode = AiRunErrorCode::Provider): static
    {
        return $this->state(fn (): array => [
            'status' => AiRunStatus::Failed,
            'started_at' => now(),
            'finished_at' => now(),
            'error_code' => $errorCode,
            'error_message' => '接続先の誤り',
        ]);
    }
}
