<?php

namespace Database\Factories\Ai;

use App\Enums\Ai\AiConversationPurpose;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStepSendKind;
use App\Enums\Ai\AiRunStepStatus;
use App\Models\Ai\AiRun;
use App\Models\Ai\AiRunStep;
use App\Models\Ai\AiRunStepText;
use App\Models\Ai\AiToolCall;
use BackedEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 既定は会話の型の待ちの実行(新しく作る)の、回の答えの処理中のステップ(番号はその実行の次の番号)。用途は送信の種別から決まる
 * (検査制約の表と同じ)。ファイルを作るの中の呼び出しは {@see self::insideToolCall()}、原文の行は {@see self::withText()}。
 *
 * @extends Factory<AiRunStep>
 */
class AiRunStepFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_run_id' => AiRun::factory()->conversation(),
            'sequence' => fn (array $attributes): int => self::nextSequence((int) $attributes['ai_run_id']),
            'send_kind' => AiRunStepSendKind::Turn,
            'purpose' => fn (array $attributes): AiConversationPurpose => self::purposeOf($attributes['send_kind']),
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'reserved_cost_usd' => '0.010000',
            'status' => AiRunStepStatus::Running,
            'started_at' => now(),
        ];
    }

    /** 送信の種別(用途は種別から決まる) */
    public function sendKind(AiRunStepSendKind $sendKind): static
    {
        return $this->state(fn (): array => ['send_kind' => $sendKind]);
    }

    /** ファイルを作る道具の中の呼び出し(中で呼んだ道具の行と同じ実行) */
    public function insideToolCall(
        AiToolCall $toolCall,
        AiRunStepSendKind $sendKind = AiRunStepSendKind::CreateFile,
    ): static {
        return $this->state(fn (): array => [
            'ai_run_id' => $toolCall->ai_run_id,
            'ai_tool_call_id' => $toolCall->id,
            'send_kind' => $sendKind,
        ]);
    }

    /** 送り直し(元のステップと同じ実行の、何回目か) */
    public function retryOf(AiRunStep $step, int $attempt = 1): static
    {
        return $this->state(fn (): array => [
            'ai_run_id' => $step->ai_run_id,
            'retried_from_step_id' => $step->id,
            'retry_attempt' => $attempt,
        ]);
    }

    /** 使用量の分かった成功(ファイルを作るの用途はコード実行の時間も持つ) */
    public function succeeded(string $costUsd = '0.001000'): static
    {
        return $this->state(fn (): array => ['status' => AiRunStepStatus::Succeeded, 'finished_at' => now()])
            ->withUsage($costUsd);
    }

    /** 使用量の分からない失敗 */
    public function failed(AiRunErrorCode $errorCode = AiRunErrorCode::Provider): static
    {
        return $this->state(fn (): array => [
            'status' => AiRunStepStatus::Failed,
            'error_code' => $errorCode,
            'finished_at' => now(),
        ]);
    }

    /** 使用量の分からない止めた */
    public function stopped(): static
    {
        return $this->state(fn (): array => ['status' => AiRunStepStatus::Stopped, 'finished_at' => now()]);
    }

    /** 使用量・単価・費用(ファイルを作るの用途はコード実行の時間も) */
    public function withUsage(string $costUsd = '0.001000'): static
    {
        return $this->state(fn (): array => [
            'input_tokens' => 10,
            'output_tokens' => 5,
            'pricing' => ['input' => 3.0, 'output' => 15.0],
            'cost_usd' => $costUsd,
            'code_execution_seconds' => fn (array $attributes): ?string => self::enumOf(
                AiConversationPurpose::class,
                $attributes['purpose'],
            ) === AiConversationPurpose::CreateFile ? '5.000' : null,
        ]);
    }

    /** 送った文と応答の原文の行も作る */
    public function withText(): static
    {
        return $this->has(AiRunStepText::factory(), 'text');
    }

    /** 実行の中の次の番号 */
    private static function nextSequence(int $runId): int
    {
        return (int) AiRunStep::query()->where('ai_run_id', $runId)->max('sequence') + 1;
    }

    /** 送信の種別(列挙か値)の用途(docs の 設計/AI/実行の記録.md 2.3。列挙の match で、種別の抜けが分かる) */
    private static function purposeOf(AiRunStepSendKind|string $sendKind): AiConversationPurpose
    {
        return match (self::enumOf(AiRunStepSendKind::class, $sendKind)) {
            AiRunStepSendKind::Turn, AiRunStepSendKind::Continuation, AiRunStepSendKind::Compaction,
            AiRunStepSendKind::CompactionFallback => AiConversationPurpose::Conversation,
            AiRunStepSendKind::Title => AiConversationPurpose::Title,
            AiRunStepSendKind::CreateFile,
            AiRunStepSendKind::WorkspaceContinuation => AiConversationPurpose::CreateFile,
        };
    }

    /**
     * 属性の値(列挙か値)を列挙にそろえる(知らない値は from が投げる)。
     *
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum
     */
    private static function enumOf(string $enum, BackedEnum|string $value): BackedEnum
    {
        return $value instanceof $enum ? $value : $enum::from($value instanceof BackedEnum ? $value->value : $value);
    }
}
